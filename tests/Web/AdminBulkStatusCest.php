<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * The bulk bars on /admin/users and /admin/staff — four actions (status,
 * role, trash, restore) sharing one form per page.
 *
 * The markup contract is pinned here because it is easy to break invisibly:
 * the row checkboxes cannot live *inside* their form (the tables hold
 * per-row forms, and a wrapper form would nest and silently break every
 * one), so they join it through the HTML5 `form` attribute instead. Nothing
 * about that looks wrong in a browser until the submit arrives with an
 * empty `ids[]`. `do` rides on the submit button's value, because four
 * actions now share the form and a fixed hidden field would pin one.
 *
 * The server-side behaviour — permission gate, self/rank/last-superadmin
 * guards, reject-not-clamp on the role target — is exercised with
 * hand-made POSTs rather than by ticking boxes, because a hand-made POST
 * is exactly what the guards exist for.
 */
final class AdminBulkStatusCest
{
    private const ADMIN_USER = 'admin';
    private const ADMIN_PASSWORD = 'Admin1234!';
    /** Every row this file plants carries the prefix; cleanup deletes by it. */
    private const PLANT_PREFIX = 'bulktest_';
    private const OPERATOR_USER = 'bulktest_operator';
    private const OPERATOR_PASSWORD = 'OperatorPass123!';

    private ?ConnectionInterface $db = null;

    public function _after(WebTester $I): void
    {
        // Planted accounts can collect `auth.login` rows, and activity_log
        // holds a foreign key onto user.id — so the log rows go first.
        $ids = $this->db()?->createCommand('SELECT [[id]] FROM {{%user}} WHERE [[username]] LIKE :p')
            ->bindValue(':p', self::PLANT_PREFIX . '%')
            ->queryColumn();

        if (is_array($ids) && $ids !== []) {
            $this->db()->createCommand()->delete('{{%activity_log}}', ['in', 'user_id', $ids])->execute();
            $this->db()->createCommand()->delete('{{%user}}', ['in', 'id', $ids])->execute();
        }

        $this->db = null;
    }

    public function usersPageOffersABulkStatusBar(WebTester $I): void
    {
        $I->wantTo('see the bulk bar, its form and its checkboxes on the user list.');
        $this->signIn($I);
        $I->amOnPage('/admin/users');

        $source = $this->flat($I->grabPageSource());

        Assert::assertMatchesRegularExpression(
            '/<form method="post" action="\/admin\/users" id="user-bulk-form"/',
            $source,
            'The bar needs a stable form the checkboxes can join by attribute.',
        );
        Assert::assertMatchesRegularExpression(
            '/<select x-model="action"/',
            $source,
            'Four actions share the bar, so the choice lives in one `action` select.',
        );
        Assert::assertMatchesRegularExpression(
            '/<input type="checkbox" data-bulk form="user-bulk-form" value="\d+"/',
            $source,
            'Each row posts its own id through the form attribute — a name="ids[]" inside a wrapper form is impossible here.',
        );
        Assert::assertMatchesRegularExpression(
            '/<input type="checkbox" form="user-bulk-form" x-bind:checked="all"/',
            $source,
            'The header box joins the same form and is bound to the derived `all` state.',
        );
        Assert::assertMatchesRegularExpression(
            '/<button type="submit" name="do" value="" x-bind:value="action"/',
            $source,
            '`do` rides on the submit button now — a fixed hidden field would pin one action.',
        );
        Assert::assertMatchesRegularExpression(
            '/<select name="status" x-model="target"/',
            $source,
            'The direction select has to be bound to the same `target` the submit reads.',
        );
        Assert::assertMatchesRegularExpression(
            '/<select name="role" x-model="roleTarget"/',
            $source,
            'The role leg binds its own target so the confirm can name it.',
        );
        Assert::assertMatchesRegularExpression(
            '/<input type="hidden" name="return_q"/',
            $source,
            'The batch is picked within a filtered view; the redirect must be able to land back on it.',
        );
        Assert::assertMatchesRegularExpression(
            '/window\.bulkUsersAsk = function \(count, action, target, role\)/',
            $source,
            'The confirm carries the live count, which a static data-confirm cannot.',
        );
        Assert::assertSame(
            0,
            preg_match('/<form[^>]*>\s*<form/', $source),
            'A form inside a form is invalid HTML and the browser drops the inner one.',
        );
        Assert::assertDoesNotMatchRegularExpression(
            '/<input type="checkbox"[^>]*\bname=/',
            self::headerCheckbox($source),
            'The header box must have no `name`: a named box would post a bare "on" with no id.',
        );
    }

    public function staffPageOffersABulkStatusBar(WebTester $I): void
    {
        $I->wantTo('see the bulk bar on the staff roster, and a role form that actually names its action.');
        // The roster must hold at least one row the acting superadmin may
        // touch, or no checkbox is drawn and the markup below proves nothing.
        $this->plantUser('staff', 'active');

        $this->signIn($I);
        $I->amOnPage('/admin/staff');

        $source = $this->flat($I->grabPageSource());

        Assert::assertMatchesRegularExpression(
            '/<form method="post" action="\/admin\/staff" id="staff-bulk-form"/',
            $source,
            'The roster needs the same stable form the user list has.',
        );
        Assert::assertMatchesRegularExpression(
            '/<select x-model="action"/',
            $source,
            'The roster bar carries the same four-action choice as the user list.',
        );
        Assert::assertMatchesRegularExpression(
            '/<button type="submit" name="do" value="" x-bind:value="action"/',
            $source,
            '`do` rides on the submit button so the four legs can share one form.',
        );
        Assert::assertMatchesRegularExpression(
            '/<input type="checkbox" data-bulk form="staff-bulk-form" value="\d+"/',
            $source,
            'Each roster row posts its own id through the form attribute.',
        );
        Assert::assertMatchesRegularExpression(
            '/<option value="trash">ট্র্যাশে পাঠান<\/option>/',
            $source,
            'A superadmin sees the trash leg; each page speaks its own vocabulary.',
        );
        Assert::assertMatchesRegularExpression(
            '/<option value="suspended">নিষ্ক্রিয় করুন<\/option>/',
            $source,
            'The roster suspends; the users list disables. Each page speaks its own vocabulary.',
        );
        Assert::assertMatchesRegularExpression(
            '/window\.bulkStaffAsk = function \(count, action, target, role\)/',
            $source,
            'The roster has its own asker with roster wording.',
        );
        Assert::assertSame(
            0,
            preg_match('/<form[^>]*>\s*<form/', $source),
            'A form inside a form is invalid HTML and the browser drops the inner one.',
        );

        // Regression guard: the per-row role dropdown used to post without a
        // `do` field, so every role change answered "অজানা অ্যাকশন।".
        Assert::assertMatchesRegularExpression(
            '/<input type="hidden" name="do" value="role">/',
            $source,
            'The role form must name its action, or the roster refuses every role change.',
        );
    }

    public function bulkSuspensionChangesEveryChosenAccountAndSkipsSelf(WebTester $I): void
    {
        $I->wantTo('suspend two planted accounts in one request while the admin’s own id stays untouched.');
        $one = $this->plantUser('user', 'active');
        $two = $this->plantUser('user', 'active');
        $adminId = $this->adminId();

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        // A hand-made POST, self id included: the UI never offers that row,
        // but the request body is not written by the UI.
        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_status',
            'status' => 'disabled',
            'ids' => [$one, $two, $adminId],
        ]);

        Assert::assertSame('disabled', $this->statusOf($one), 'The first chosen account must be suspended.');
        Assert::assertSame('disabled', $this->statusOf($two), 'The second chosen account must be suspended.');
        Assert::assertSame('active', $this->statusOf($adminId), 'The acting superadmin’s own id must be skipped by the guards.');

        Assert::assertGreaterThan(
            0,
            (int) $this->db()->createCommand(
                "SELECT COUNT(*) FROM {{%activity_log}} WHERE [[action]] = 'admin.user.bulk_status' AND [[metadata]] LIKE :needle",
            )->bindValue(':needle', '%' . $one . '%')->queryScalar(),
            'A bulk run moves many accounts at once and must leave one audit entry naming them.',
        );
    }

    public function bulkActivationPutsSuspendedAccountsBack(WebTester $I): void
    {
        $I->wantTo('reactivate a suspended account through the same bar.');
        $id = $this->plantUser('user', 'disabled');

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_status',
            'status' => 'active',
            'ids' => [$id],
        ]);

        Assert::assertSame('active', $this->statusOf($id), 'The chosen account must come back as active.');
    }

    public function bulkStatusRefusesAnEmptySelection(WebTester $I): void
    {
        $I->wantTo('be refused a bulk run that names no account at all.');
        $id = $this->plantUser('user', 'active');

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_status',
            'status' => 'disabled',
            'ids' => [],
        ]);

        Assert::assertSame('active', $this->statusOf($id), 'An empty batch must not change anything.');
    }

    public function staffBulkSuspendsTheChosenOperatorsAndSkipsSelf(WebTester $I): void
    {
        $I->wantTo('suspend two planted operators in one request while the superadmin’s own row stays active.');
        $one = $this->plantUser('staff', 'active');
        $two = $this->plantUser('staff', 'active');
        $adminId = $this->adminId();

        $this->signIn($I);
        $I->amOnPage('/admin/staff');
        $csrf = $I->grabAttributeFrom('#staff-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_status',
            'status' => 'suspended',
            'ids' => [$one, $two, $adminId],
        ]);

        Assert::assertSame('suspended', $this->statusOf($one), 'The first chosen operator must be suspended.');
        Assert::assertSame('suspended', $this->statusOf($two), 'The second chosen operator must be suspended.');
        Assert::assertSame('active', $this->statusOf($adminId), 'Nobody edits their own roster row — the guard is per-request, not per-render.');
    }

    public function staffBulkActivationRestoresOperators(WebTester $I): void
    {
        $I->wantTo('reactivate a suspended operator through the roster bar.');
        $id = $this->plantUser('staff', 'suspended');

        $this->signIn($I);
        $I->amOnPage('/admin/staff');
        $csrf = $I->grabAttributeFrom('#staff-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_status',
            'status' => 'active',
            'ids' => [$id],
        ]);

        Assert::assertSame('active', $this->statusOf($id), 'The chosen operator must come back as active.');
    }

    public function aPlainOperatorCannotBulkSuspendAnybody(WebTester $I): void
    {
        $I->wantTo('prove the gate is the permission, not the hidden checkboxes.');
        $target = $this->plantUser('user', 'active');
        $operator = $this->plantUser('staff', 'active', self::OPERATOR_PASSWORD, self::OPERATOR_USER);

        $this->signIn($I, self::OPERATOR_USER, self::OPERATOR_PASSWORD);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('meta[name="_csrf"]', 'content');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_status',
            'status' => 'disabled',
            'ids' => [$target],
        ]);

        Assert::assertSame('active', $this->statusOf($target), 'A role without users.update must not move an account, however the POST is shaped.');
        Assert::assertSame('active', $this->statusOf($operator), 'The operator’s own planted row must be untouched too.');
    }

    public function bulkRoleChangePromotesTheChosenUsers(WebTester $I): void
    {
        $I->wantTo('promote two planted accounts in one request while the superadmin’s own row keeps its rank.');
        $one = $this->plantUser('user', 'active');
        $two = $this->plantUser('user', 'active');
        $adminId = $this->adminId();

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_role',
            'role' => 'admin',
            'ids' => [$one, $two, $adminId],
        ]);

        Assert::assertSame('admin', $this->roleOf($one), 'The first chosen account must be promoted.');
        Assert::assertSame('admin', $this->roleOf($two), 'The second chosen account must be promoted.');
        Assert::assertSame(
            'superadmin',
            $this->roleOf($adminId),
            'Self and rank guards must answer the role leg exactly as they answer status.',
        );
    }

    public function bulkRoleRejectsAnUnknownRoleInsteadOfClampingToUser(WebTester $I): void
    {
        $I->wantTo('refuse a bogus role outright rather than quietly demoting the batch.');
        $id = $this->plantUser('staff', 'active');

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_role',
            'role' => 'wizard',
            'ids' => [$id],
        ]);

        Assert::assertSame(
            'staff',
            $this->roleOf($id),
            'Reject-not-clamp is the contract on both paths: a clamp fallback would have demoted this row to "user".',
        );
    }

    public function theSingleRowRoleEditRejectsAnUnknownRoleInsteadOfClamping(WebTester $I): void
    {
        $I->wantTo('accept a valid role on the row dropdown and reject a bogus one instead of clamping it.');
        $id = $this->plantUser('user', 'active');

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'role',
            'user_id' => $id,
            'role' => 'staff',
        ]);
        Assert::assertSame('staff', $this->roleOf($id), 'A valid role must still apply — rejecting must not break the ordinary edit.');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'role',
            'user_id' => $id,
            'role' => 'wizard',
        ]);
        Assert::assertSame(
            'staff',
            $this->roleOf($id),
            'The old clamp fallback would have demoted the row to "user" on a typo.',
        );
    }

    public function bulkTrashSparesAdminAndSelfAndRestoreBringsTheRestBack(WebTester $I): void
    {
        $I->wantTo('trash plain users in one request — sparing admin rows and my own — then restore them.');
        $plain = $this->plantUser('user', 'active');
        $adminRow = $this->plantUser('admin', 'active');
        $adminId = $this->adminId();

        $this->signIn($I);
        $I->amOnPage('/admin/users');
        $csrf = $I->grabAttributeFrom('#user-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_trash',
            'ids' => [$plain, $adminRow, $adminId],
        ]);

        Assert::assertNotNull($this->deletedAtOf($plain), 'A plain user must be trashed by the trash leg.');
        Assert::assertNull($this->deletedAtOf($adminRow), 'An admin row is never trashed — the single-row path refuses it too.');
        Assert::assertNull($this->deletedAtOf($adminId), 'The acting superadmin’s own id must survive the trash leg.');

        $I->sendAjaxPostRequest('/admin/users', [
            '_csrf' => $csrf,
            'do' => 'bulk_restore',
            'ids' => [$plain, $adminRow, $adminId],
        ]);

        Assert::assertNull($this->deletedAtOf($plain), 'The restore leg must bring the trashed row back.');
        Assert::assertNull($this->deletedAtOf($adminId), 'A row that was never trashed is out of the restore leg’s scope.');
    }

    public function staffBulkRoleAndTrashLegsMoveRosterRows(WebTester $I): void
    {
        $I->wantTo('promote, trash and restore a roster row through the staff bar’s three new legs.');
        $id = $this->plantUser('staff', 'active');

        $this->signIn($I);
        $I->amOnPage('/admin/staff');
        $csrf = $I->grabAttributeFrom('#staff-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_role',
            'role' => 'admin',
            'ids' => [$id],
        ]);
        Assert::assertSame('admin', $this->roleOf($id), 'The roster’s role leg must promote the chosen operator.');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_trash',
            'ids' => [$id],
        ]);
        Assert::assertNotNull($this->deletedAtOf($id), 'The trash leg must move the chosen operator out of the roster.');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_restore',
            'ids' => [$id],
        ]);
        Assert::assertNull($this->deletedAtOf($id), 'The restore leg must bring the operator back.');
    }

    public function theStaffSingleRowRestoreFormActuallyRestores(WebTester $I): void
    {
        $I->wantTo('use the per-row রিস্টোর form, which posts do=restore and used to answer "অজানা অ্যাকশন।".');
        $id = $this->plantUser('staff', 'active');

        $this->signIn($I);
        $I->amOnPage('/admin/staff');
        $csrf = $I->grabAttributeFrom('#staff-bulk-form input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_trash',
            'ids' => [$id],
        ]);
        Assert::assertNotNull($this->deletedAtOf($id), 'The row must start trashed or the restore below proves nothing.');

        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'restore',
            'id' => $id,
        ]);
        Assert::assertNull($this->deletedAtOf($id), 'apply() had no restore case, so the button it renders was dead.');
    }

    public function anAdminCannotMintASuperadminThroughEitherRolePath(WebTester $I): void
    {
        $I->wantTo('hold superadmin assignment behind manage_superadmin, which the admin role does not carry.');
        $actor = $this->plantUser('admin', 'active', 'AdminActPass123!');
        $subject = $this->plantUser('staff', 'active');
        $bystander = $this->plantUser('staff', 'active');

        $this->signIn($I, $this->usernameOf($actor), 'AdminActPass123!');
        $I->amOnPage('/admin/staff');
        $csrf = $I->grabAttributeFrom('meta[name="_csrf"]', 'content');

        // The single-row dropdown sits inside staff.update, which admins hold.
        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'role',
            'id' => $subject,
            'role' => 'superadmin',
        ]);
        Assert::assertSame(
            'staff',
            $this->roleOf($subject),
            'A plain admin must not be able to mint a superadmin through the row dropdown.',
        );

        // And the bulk leg must refuse the whole batch before touching a row.
        $I->sendAjaxPostRequest('/admin/staff', [
            '_csrf' => $csrf,
            'do' => 'bulk_role',
            'role' => 'superadmin',
            'ids' => [$subject, $bystander],
        ]);
        Assert::assertSame('staff', $this->roleOf($subject), 'The batch must be refused, not partly applied.');
        Assert::assertSame('staff', $this->roleOf($bystander), 'The bystander proves the refusal happened before any row was touched.');
    }

    /** A live row under the plant prefix, so cleanup can find it by prefix. */
    private function plantUser(string $role, string $status, ?string $password = null, ?string $username = null): int
    {
        $suffix = str_replace('.', '', uniqid('', true));
        $now = date('Y-m-d H:i:s');

        $this->db()->createCommand()->insert('{{%user}}', [
            'username' => $username ?? self::PLANT_PREFIX . $suffix,
            'full_name' => 'Bulk Test ' . $suffix,
            'phone' => '017' . substr($suffix, -8),
            'password_hash' => password_hash($password ?? 'BulkTestPass123!', PASSWORD_DEFAULT),
            'role' => $role,
            'status' => $status,
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db()->getLastInsertID();
    }

    private function signIn(WebTester $I, string $user = self::ADMIN_USER, string $password = self::ADMIN_PASSWORD): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => $user,
            'password' => $password,
        ]);
        Assert::assertStringContainsString(
            'লগআউট',
            $I->grabPageSource(),
            'The session must be live, or every POST below lands on the login page.',
        );
    }

    private function adminId(): int
    {
        return (int) $this->db()
            ->createCommand('SELECT [[id]] FROM {{%user}} WHERE [[username]] = :u LIMIT 1')
            ->bindValue(':u', self::ADMIN_USER)
            ->queryScalar();
    }

    private function statusOf(int $id): string
    {
        return (string) $this->db()
            ->createCommand('SELECT [[status]] FROM {{%user}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryScalar();
    }

    private function roleOf(int $id): string
    {
        return (string) $this->db()
            ->createCommand('SELECT [[role]] FROM {{%user}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryScalar();
    }

    private function usernameOf(int $id): string
    {
        return (string) $this->db()
            ->createCommand('SELECT [[username]] FROM {{%user}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryScalar();
    }

    private function deletedAtOf(int $id): ?string
    {
        $value = $this->db()
            ->createCommand('SELECT [[deleted_at]] FROM {{%user}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryScalar();

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Tag newlines collapse, so a directive split across lines still matches. */
    private function flat(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    private static function headerCheckbox(string $source): string
    {
        preg_match('/<input type="checkbox" form="user-bulk-form" x-bind:checked="all"[^>]*>/', $source, $m);

        return $m[0] ?? '';
    }

    private function db(): ConnectionInterface
    {
        return $this->db ??= (new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        )))->get(ConnectionInterface::class);
    }
}
