<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Service\StatusPresenter;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * The bulk settle bar on the admin order queue.
 *
 * The settling itself is covered against the database by
 * AdminServiceRequestTest, so what is left to prove here is the shape of the
 * markup the operator is handed — and there is one specific reason this file
 * exists. The cancel button's handler has to reach the *form*, not the button:
 * Alpine's `$el` is the element a directive is attached to, so a handler that
 * read `$el` (or `this.$el`) searched the button for row checkboxes, found
 * none, and cleared only its own counters. The bar vanished, the count went to
 * zero, and every row stayed ticked — the operator was left looking at a
 * hidden bar with ids still sitting in the form body. Nothing about the
 * rendered HTML looked wrong, which is exactly why it needs pinning here.
 *
 * Read-only with respect to *orders*: it submits the bar's export button but
 * never its settle button, because the Web suite runs against the shared
 * development database rather than a fixture set, and a settle moves money. The
 * export is the safe half by construction — it only reads the selection and
 * writes a file into the response — so pressing it is what lets the POST path
 * itself be covered here rather than only its markup. The other thing this file
 * writes is a throwaway row in the bulk-job queue, marked as its own and purged
 * either side of every test — inert by construction, since nothing in the test
 * run ever executes the worker, and the only way to see the progress panel is to
 * give it a job.
 */
final class AdminOrderQueueBulkCest
{
    private const ADMIN_USER = 'admin';
    private const ADMIN_PASSWORD = 'Admin1234!';

    /**
     * The requester name every row this file plants carries.
     *
     * It is the cleanup key as well as a label: `recentFor()` selects by
     * `requested_by`, so the planted row still belongs to the real admin and
     * still renders for them, while remaining trivially distinguishable from a
     * batch a human actually submitted. A row left over from a run that was
     * killed mid-test is therefore still findable — which it was not when the
     * cleanup keyed on the returned insert id alone.
     */
    private const REQUESTER_MARKER = 'admin [bulk-cest]';

    private ?ConnectionInterface $db = null;
    private int $jobId = 0;

    public function _before(WebTester $I): void
    {
        // Both halves of the queue pair are order-dependent — one asserts the
        // panel is empty, the other plants a row to make it non-empty — and the
        // suite is shuffled. Purging the marker on the way in makes each test
        // independent of the shuffle *and* of any earlier run that died before
        // its own cleanup, which is the only way a leaked row could have
        // survived the hook below.
        $this->purgeSeededJobs();
    }

    /**
     * Public on purpose.
     *
     * A Cest's hooks are not called by the test class itself the way a
     * `Test\Unit`'s are: `Codeception\Test\Cest::executeHook()` reaches in from
     * outside with `is_callable([$this->testInstance, '_after'])`, and a
     * `protected` hook fails that check from a foreign scope. It is then skipped
     * without a word, so a protected `_after` looks like it cleans up and does
     * nothing at all — which is exactly how the first two runs of this file each
     * left a row behind for the next one to trip over.
     */
    public function _after(WebTester $I): void
    {
        $this->purgeSeededJobs();
    }

    public function theQueueWrapsTheSelectionInOnePostForm(WebTester $I): void
    {
        $I->wantTo('see the selected orders carried by a single POST form that knows where to go back to.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $I->seeElement('form[method="post"][action="/admin/transactions"]');
        $I->seeElement('form[action="/admin/transactions"] input[name="_csrf"]');

        $source = $this->flat($I->grabPageSource());

        // `do` used to be a hidden field, on the reasoning that the settle was
        // the form's only action. It is not any more, and a hidden field cannot
        // express two of them: the action now rides on each button's own
        // name/value, so the markup alone decides which request is made.
        Assert::assertStringNotContainsString(
            '<input type="hidden" name="do"',
            $source,
            'A hidden `do` can only name one action; with settle and export on the same bar it would silently pick one for every button.',
        );
        Assert::assertMatchesRegularExpression(
            '/<button type="submit" name="do" value="bulk_status"/',
            $source,
            'The settle must post its own action, or the server is guessing which of the two was asked for.',
        );

        foreach (['return_kind', 'return_q', 'return_status', 'return_page', 'return_sort', 'return_dir'] as $field) {
            $I->seeElement('form[action="/admin/transactions"] input[name="' . $field . '"]');
        }
        Assert::assertMatchesRegularExpression(
            '/name="return_kind" value="service"/',
            $source,
            'The bar has to carry the tab it was drawn on, or the redirect lands on the other queue.',
        );

        Assert::assertSame(
            0,
            preg_match('/<form[^>]*>\s*<form/', $source),
            'A form inside a form is invalid HTML and the browser drops the inner one, taking the whole bar with it.',
        );
    }

    public function theBarAndItsSpacerStayHiddenUntilSomethingIsTicked(WebTester $I): void
    {
        $I->wantTo('see neither the bar nor the gap it floats over until an order is ticked.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $source = $this->flat($I->grabPageSource());

        // The spacer is what keeps the last row clickable once the bar covers
        // the bottom of the list; it appears and disappears with the bar.
        Assert::assertMatchesRegularExpression(
            '/<div class="h-28" x-show="count > 0" x-cloak>/',
            $source,
            'The spacer must be shown exactly when the bar is, or it is dead space that hides the final rows.',
        );
        Assert::assertMatchesRegularExpression(
            '/<div class="fixed inset-x-0[^"]*"[^>]*x-show="count > 0"[^>]*x-cloak>/',
            $source,
            'The bar is revealed by the selection count and must carry x-cloak so it never flashes before Alpine boots.',
        );
        Assert::assertMatchesRegularExpression(
            '/<div class="fixed inset-x-0[^"]*"[^>]*x-show="count > 0"/',
            $source,
            'The bar has to be bound to the live count, not merely present in the page.',
        );
    }

    public function cancellingReachesBackToTheFormAndUnticksEveryRow(WebTester $I): void
    {
        $I->wantTo('cancel a selection by clearing the rows, not just the counters.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $source = $this->flat($I->grabPageSource());

        // The regression this file exists for. `$el` inside a handler is the
        // button the handler is on, so `clear($el)` silently matched nothing.
        Assert::assertMatchesRegularExpression(
            '/@click="clear\(\$el\.form\)"/',
            $source,
            'Cancel must hand the handler the form it lives in; `$el` alone is the button, and its subtree has no row checkboxes.',
        );
        Assert::assertStringNotContainsString(
            '@click="clear()"',
            $source,
            'A zero-argument cancel cannot reach the rows and leaves them ticked behind a hidden bar.',
        );
        Assert::assertStringNotContainsString(
            'this.$el',
            $source,
            '`this` in an Alpine expression is the scope, not the data object, so `this.$el` is not the form either.',
        );
        Assert::assertMatchesRegularExpression(
            '/clear\(el\)\s*\{\s*el\.querySelectorAll\(\'tbody input\[type=checkbox\]\'\)/',
            $source,
            'The handler has to reach the row boxes themselves, not just the count it already knows.',
        );
    }

    public function selectAllDrivesTheRowsWithoutBeingSubmitted(WebTester $I): void
    {
        $I->wantTo('tick the whole page from the header without posting the header box itself.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $source = $this->flat($I->grabPageSource());

        Assert::assertMatchesRegularExpression(
            '/<input type="checkbox" x-bind:checked="all"[^>]*>/',
            $source,
            'The header box is bound to the derived `all` state so a partial selection unticks it.',
        );
        Assert::assertDoesNotMatchRegularExpression(
            '/<input type="checkbox"[^>]*\bname=/',
            self::headerCheckbox($source),
            'The header box must have no `name`: it is a control, and a named box would post a bare "on" with no id.',
        );
        Assert::assertMatchesRegularExpression(
            '/@change="\$el\.form\.querySelectorAll\(\'tbody input\[type=checkbox\]\'\)/',
            self::headerCheckbox($source),
            'Select-all writes the rows through the form for the same reason cancel reads them through it.',
        );

        // The rows themselves are plain, so the batch still works without JS.
        Assert::assertMatchesRegularExpression(
            '/<input type="checkbox" name="ids\[\]" value="\d+"/',
            $source,
            'Each row posts its own id; that is the whole request body.',
        );
    }

    public function theApplyButtonWaitsForAStatusAndWarnsAboutRefunds(WebTester $I): void
    {
        $I->wantTo('be told the batch is refused until a status is chosen, and warned that it moves money.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $source = $this->flat($I->grabPageSource());

        Assert::assertMatchesRegularExpression(
            '/<select name="status" x-model="target"/',
            $source,
            'The select has to be bound to the same `target` the submit button reads.',
        );
        Assert::assertMatchesRegularExpression(
            '/<button type="submit" name="do" value="bulk_status"[^>]*x-bind:disabled="target === \'\'"/',
            $source,
            'Submitting with no status would be a no-op the operator reads as a success.',
        );

        // The select is deliberately NOT `required`, and the disabled button is
        // what enforces the rule instead. A `required` select applies to the
        // whole form, so it would block the export button at the browser's own
        // validation step — with no message about which field was wrong and no
        // file — for an operator who never asked for a status in the first place.
        Assert::assertDoesNotMatchRegularExpression(
            '/<select[^>]*\brequired\b/',
            $source,
            'A required status select blocks the export action, which needs no status.',
        );

        Assert::assertMatchesRegularExpression(
            '/@submit="const btn = \$event\.submitter;/',
            $source,
            'The refund warning has to be able to tell the two buttons apart, or it fires on the export too.',
        );
        Assert::assertMatchesRegularExpression(
            '/btn\.value === \'bulk_status\'/',
            $source,
            'Only the settle moves money; the export only reads, and prompting for it would train operators to dismiss the dialog that matters.',
        );
        Assert::assertStringContainsString(
            'ব্যালেন্স',
            $source,
            'The warning has to name the balance, since that is the consequence the operator cannot undo.',
        );

        // The options are the settleable statuses and nothing else — offering
        // "all" here would mean a batch that is silently not a batch.
        $expected = [''];
        foreach (StatusPresenter::REQUEST_STATUSES as $status) {
            $expected[] = '<option value="' . $status . '">' . StatusPresenter::label($status) . '</option>';
        }
        foreach ($expected as $option) {
            Assert::assertStringContainsString(
                $option,
                $source,
                'The bar offers every settleable status, labelled for the operator.',
            );
        }
    }

    public function theSameSelectionCanBeExportedWithoutSettlingIt(WebTester $I): void
    {
        $I->wantTo('hand the ticked orders over as a file, on the same bar and the same selection.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $source = $this->flat($I->grabPageSource());

        $I->seeElement('form[action="/admin/transactions"] button[name="do"][value="bulk_export"]');

        // A plain submit, inside the same form, so the export carries the very
        // same `ids[]` body the settle would. A second form — or a link that
        // cannot express a selection — would mean ticking the orders twice.
        Assert::assertMatchesRegularExpression(
            '/<button type="submit" name="do" value="bulk_export"[^>]*>/',
            $source,
            'Export is a submit button so it inherits the selection; anything else would need the ids re-sent by JavaScript.',
        );
        Assert::assertStringNotContainsString(
            '<a href="/admin/transactions/export"',
            $source,
            'A link cannot carry a selection, so an export behind one would always export the empty set.',
        );
        Assert::assertSame(
            1,
            preg_match_all('/<form method="post" action="\/admin\/transactions"/', $source),
            'Both actions share one form; a second POST form would be a second place for the ids to go missing.',
        );
    }

    public function theExportAnswersWithAFileRatherThanAnotherPage(WebTester $I): void
    {
        $I->wantTo('get the ticked orders back as a file, not as a page to read.');
        $orderId = $this->firstServiceOrderId();
        $reference = (string) $this->db()
            ->createCommand('SELECT [[reference]] FROM {{%transaction}} WHERE [[id]] = :id')
            ->bindValue(':id', $orderId)
            ->queryScalar();

        $this->signIn($I);
        // Filtered onto this one order so that the row ticked below is the row
        // the export is then asked for: the first page of a shared database is
        // not this file's to assume anything about.
        $I->amOnPage('/admin/transactions?kind=service&q=' . urlencode($reference));

        $I->checkOption('input[name="ids[]"][value="' . $orderId . '"]');

        // Pressed, not reconstructed. `do` rides on the button's own name/value
        // so that the request itself says which action was asked for; a test
        // that added a `do` field of its own would pass even if the buttons had
        // no name at all, which is the regression that made the settle and the
        // export indistinguishable to the server.
        $I->click('button[value="bulk_export"]');

        $body = $I->grabPageSource();

        Assert::assertStringStartsWith(
            "\xEF\xBB\xBF",
            $body,
            'The response is the file itself, BOM and all, rather than an HTML page wrapping it.',
        );
        // The response headers are deliberately not asserted here: this
        // browser module exposes no way to read them, and the filename is
        // covered where it is built, in OrderExportService.
        Assert::assertStringContainsString(
            'রেফারেন্স',
            $body,
            'It opens on the labelled header row, so the first thing in the file is a column name and not an order.',
        );
        Assert::assertStringContainsString(
            $reference,
            $body,
            'The ticked order is in it. The other branch of this form settles, and this one must carry the selection through to the file.',
        );
    }

    public function anExportOfNothingComesBackAsAFlashRatherThanAnEmptyFile(WebTester $I): void
    {
        $I->wantTo('be sent back to the list when the selection exports to nothing.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        // Pressed with nothing ticked, which is what a stale selection — or an
        // operator who has lost track of the bar — actually does.
        $I->click('button[value="bulk_export"]');

        // A header row and no data is indistinguishable from a broken export, so
        // this one branch is the one that does redirect — and it has to say why.
        $I->seeInCurrentUrl('/admin/transactions');
        $I->see('নির্বাচিত অর্ডারের তালিকা খালি, তাই ফাইল তৈরি হয়নি।');
    }

    public function theQueuePanelTellsTheOperatorWhereTheirBatchGotTo(WebTester $I): void
    {
        $I->wantTo('watch a queued batch instead of guessing whether it ran.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        // Present even with nothing queued, because a section that only appears
        // once it has a job in it is invisible on the day the operator first
        // submits one — and "no recent jobs" is the answer to "did my cron die".
        $I->see('সাম্প্রতিক বাল্ক কাজ');
        $I->see('এখনো কোনো বাল্ক কাজ নেই।');
    }

    public function aQueuedBatchIsReportedWithItsOwnProgress(WebTester $I): void
    {
        $I->wantTo('see the half-finished batch on the page, not just be told it was queued.');
        $this->seedJob(40, 25, 63, '25/40');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=service');

        $source = $this->flat($I->grabPageSource());

        Assert::assertMatchesRegularExpression(
            '/<div[^>]*role="progressbar" aria-valuenow="63"/',
            $source,
            'The bar on its own promises a wait it cannot report on, so a job row has to carry the progress it reached.',
        );
        Assert::assertMatchesRegularExpression(
            '/style="width: 63%"/',
            $source,
            'The filled width is the only place the number becomes legible without reading the aria attribute.',
        );

        // The requester, because a queue page loaded by any member of staff
        // cannot answer "was this mine?" from the status alone.
        Assert::assertMatchesRegularExpression(
            '/#\d+ · 40 টি অর্ডার/',
            $source,
            'A job row names its batch, so two batches in a queue can be told apart.',
        );
        Assert::assertStringContainsString(
            '25/40',
            $source,
            'The checkpoint is what tells the operator the batch is moving rather than stuck.',
        );
    }

    public function theTopUpTabOffersNoBulkAffordanceAtAll(WebTester $I): void
    {
        $I->wantTo('find no bulk bar on the top-up tab, where a status change is meaningless.');
        $this->signIn($I);
        $I->amOnPage('/admin/transactions?kind=recharge');

        $source = $this->flat($I->grabPageSource());

        // This page is populated, so the absence is a decision rather than an
        // accident of an empty list.
        Assert::assertGreaterThan(
            0,
            preg_match_all('/<tr/', $source),
            'The top-up tab must actually have rows, or this test proves nothing.',
        );
        Assert::assertStringNotContainsString(
            'bulk_status',
            $source,
            'A top-up has no service to attach and no status to settle; the bar must not be drawn here.',
        );
        Assert::assertStringNotContainsString(
            'name="ids[]"',
            $source,
            'No row checkboxes on a tab where ticking one could not mean anything.',
        );
        Assert::assertStringNotContainsString(
            'clear($el.form)',
            $source,
            'No bar, no handler.',
        );
    }

    /**
     * A real order off the queue, so the export is asked for something that exists.
     *
     * The caller then filters the list down to this one and ticks it, rather
     * than ticking whatever happens to be on the first page: the point of the
     * export test is what comes back over HTTP, and the top of a shared
     * database's queue is not this file's to assume anything about.
     */
    private function firstServiceOrderId(): int
    {
        $id = (int) $this->db()
            ->createCommand('SELECT [[id]] FROM {{%transaction}} WHERE [[service_id]] IS NOT NULL ORDER BY [[id]] DESC LIMIT 1')
            ->queryScalar();

        Assert::assertGreaterThan(
            0,
            $id,
            'The queue must actually have a row on it, or an export of it proves nothing.',
        );

        return $id;
    }

    /**
     * A queued batch for the panel to render, owned by the admin who will see it.
     *
     * The payload names ids that do not exist, and that is safe: a payload is
     * inert until a worker claims the row, and no test here runs one. Were it
     * ever drained by a stray cron against this database, `settleMany()` would
     * skip every id as missing — the same as it would for any deleted order.
     */
    private function seedJob(int $total, int $processed, int $progress, string $message): void
    {
        $db = $this->db();
        $adminId = (int) $db
            ->createCommand('SELECT [[id]] FROM {{%user}} WHERE [[username]] = :u LIMIT 1')
            ->bindValue(':u', self::ADMIN_USER)
            ->queryScalar();

        $now = date('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%admin_bulk_job}}', [
            'kind' => 'settle_status',
            'status' => 'processing',
            'payload' => json_encode(['ids' => [999999001, 999999002], 'status' => 'processing']),
            'requested_by' => $adminId,
            'requested_by_name' => self::REQUESTER_MARKER,
            'total' => $total,
            'processed' => $processed,
            'cursor' => $processed,
            'chunk_size' => 25,
            'progress' => $progress,
            'message' => $message,
            'attempts' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->jobId = (int) $db->getLastInsertID();
    }

    /**
     * Remove every row this file ever planted, in one statement.
     *
     * Deleting by the marker rather than by the insert id means the cleanup
     * still works when the test failed *before* the id could be recorded, and
     * when the row was left by a previous, differently-ordered run. The name is
     * a fixed string that no batch submitted through the bar can ever carry,
     * so the predicate is an identity check and cannot reach a real job.
     */
    private function purgeSeededJobs(): void
    {
        $this->db()
            ->createCommand()
            ->delete('{{%admin_bulk_job}}', ['requested_by_name' => self::REQUESTER_MARKER])
            ->execute();

        $this->jobId = 0;
    }

    private function db(): ConnectionInterface
    {
        return $this->db ??= (new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        )))->get(ConnectionInterface::class);
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::ADMIN_USER,
            'password' => self::ADMIN_PASSWORD,
        ]);
        Assert::assertStringContainsString(
            'লগআউট',
            $I->grabPageSource(),
            'The admin session must be live, or every assertion below reads the login page.',
        );
    }

    /** Tag newlines collapse, so a directive split across lines still matches. */
    private function flat(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    private static function headerCheckbox(string $source): string
    {
        preg_match('/<input type="checkbox" x-bind:checked="all"[^>]*>/', $source, $m);

        return $m[0] ?? '';
    }
}
