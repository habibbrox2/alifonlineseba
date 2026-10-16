<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * Notification queue routing, proven through the *real* router.
 *
 * The Functional suite cannot prove this: its `call()` helper hands the action
 * a hand-built CurrentRoute with synthetic arguments, so a route that never
 * produces `{action}` in production still looked wired — which is exactly how
 * the delete button's requeue bug shipped. This file posts the URLs over HTTP
 * at the live app (PhpBrowser → the dev server → config/common/routes.php),
 * so the assertion is about FastRoute's pattern and the action's reading of
 * it, not about a test's idea of either.
 */
final class AdminApiRouterCest
{
    private const ADMIN_USER = 'admin';
    private const ADMIN_PASSWORD = 'Admin1234!';

    /**
     * The dedupe key every queue row this file plants carries.
     *
     * Cleanup key and identity check in one: a string no business event can
     * generate, so the purge cannot reach a row a human is waiting on — and,
     * because the column is UNIQUE (the queue's idempotency guarantee), a
     * fixed marker also means the purge must run *before* the plant, or a run
     * killed mid-test would leave a row the next insert collides with.
     */
    private const QUEUE_MARKER = 'web-router-cest-dead';

    private ?ConnectionInterface $db = null;

    public function _before(WebTester $I): void
    {
        $this->purgePlantedQueueRows();
    }

    /**
     * Public on purpose: Codeception reaches the hook in from outside with
     * `is_callable([$instance, '_after'])`, which a `protected` method fails
     * from a foreign scope — silently skipped, cleaning up nothing.
     */
    public function _after(WebTester $I): void
    {
        $this->purgePlantedQueueRows();
    }

    public function notificationDeleteIsRoutedThroughTheRealRouter(WebTester $I): void
    {
        $I->wantTo('see the delete URL reach the action\'s delete branch through the real router.');
        $this->_bearerAdmin($I);
        $rowId = $this->plantDeadQueueRow();

        $I->sendAjaxPostRequest('/api/admin/notifications/' . $rowId . '/delete');

        $I->seeResponseCodeIs(200);
        $payload = $this->decode($I);
        Assert::assertTrue(
            $payload['success'] ?? false,
            'The delete POST must succeed: ' . (string) ($payload['message'] ?? ''),
        );
        Assert::assertSame(
            'নোটিফিকেশনটি মুছে ফেলা হয়েছে।',
            $payload['data']['message'] ?? null,
            'Only the delete branch says this; a fall-through to retry answers with the requeue message instead.',
        );
        Assert::assertSame(
            [],
            $this->plantedQueueRows(),
            'The dead row must actually be gone, not merely requeued under the same id.',
        );
    }

    public function notificationDeleteOnMissingRowIs404ThroughRouter(WebTester $I): void
    {
        $I->wantTo('see a delete of a row that does not exist answer 404 through the real router.');
        $this->_bearerAdmin($I);

        $I->sendAjaxPostRequest('/api/admin/notifications/999999999/delete');

        // Before the route merge this URL fell through to the retry branch and
        // answered 400 "শুধুমাত্র dead-letter …" — the status is the proof the
        // `{action}` segment reached the action at all.
        $I->seeResponseCodeIs(404);
        $payload = $this->decode($I);
        Assert::assertFalse($payload['success'] ?? true, 'A missing row is a failure.');
        Assert::assertSame(
            'নোটিফিকেশনটি পাওয়া যায়নি।',
            $payload['message'] ?? null,
            '404 with the not-found message is the delete branch\'s answer for an absent row.',
        );
    }

    public function notificationUnknownActionIs400(WebTester $I): void
    {
        $I->wantTo('see an unknown notification action rejected with 400, not left to the router.');
        $this->_bearerAdmin($I);

        $I->sendAjaxPostRequest('/api/admin/notifications/1/oops');

        // Before the merge no route matched this URL at all and the router's
        // 404 page answered; 400 here is what proves the {action} segment is
        // read and validated rather than pattern-matched away.
        $I->seeResponseCodeIs(400);
        $payload = $this->decode($I);
        Assert::assertFalse($payload['success'] ?? true, 'An unknown action is a failure.');
        Assert::assertSame(
            'Unknown notification action.',
            $payload['message'] ?? null,
            'The action must name the rejection itself, not fall into retry or delete.',
        );
    }

    public function notificationRetryIsStillRouted(WebTester $I): void
    {
        $I->wantTo('see retry keep working exactly as before after the routes were merged.');
        $this->_bearerAdmin($I);

        // No row with this id: the requeue guard refuses, which is the same
        // answer the endpoint gave before {action} existed — proof the merge
        // did not break the branch the old static route was built for.
        $I->sendAjaxPostRequest('/api/admin/notifications/1/retry');

        $I->seeResponseCodeIs(400);
        $payload = $this->decode($I);
        Assert::assertFalse($payload['success'] ?? true, 'A retry with no dead row is a failure.');
        Assert::assertSame(
            'শুধুমাত্র dead-letter অনুরোধ আবার চালানো যায়।',
            $payload['message'] ?? null,
        );
    }

    /**
     * 401 with no credential at all — which only the real middleware stack can
     * prove: the Functional harness injects an identity by construction and
     * AdminApiMiddleware never runs there, so this lives over HTTP instead.
     * The header state is fresh per test (InnerBrowser::_after drops it), so
     * no token from a login in another test can leak into this request.
     */
    public function rechargeReceiptUnauthorizedWithoutToken(WebTester $I): void
    {
        $I->wantTo('see a token-less request to the receipt stream refused with 401 before the action runs.');
        $I->sendAjaxGetRequest('/api/admin/recharges/1/receipt');

        $I->seeResponseCodeIs(401);
        $payload = $this->decode($I);
        Assert::assertFalse($payload['success'] ?? true, 'An unauthenticated request must be refused.');
        Assert::assertSame(
            'Authentication required.',
            $payload['message'] ?? null,
            'AdminApiMiddleware/AuthMiddleware answer API paths with the 401 JSON envelope.',
        );
    }

    /**
     * A dead queue row of this file's own, keyed by the marker.
     *
     * Mirrors what AdminApiTest::testNotificationDeleteRemovesDeadRow enqueues,
     * as a direct insert so the status is `dead` from the first write — the
     * state the delete guard allows and the state a requeue would accept,
     * which is what makes the retry-vs-delete fall-through observable.
     */
    private function plantDeadQueueRow(): int
    {
        $db = $this->db();
        $adminId = (int) $db
            ->createCommand('SELECT [[id]] FROM {{%user}} WHERE [[username]] = :u LIMIT 1')
            ->bindValue(':u', self::ADMIN_USER)
            ->queryScalar();

        $now = date('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%notification_queue}}', [
            'notification_id' => null,
            'event' => 'test.dead',
            'user_id' => $adminId,
            'channel' => 'telegram',
            'status' => 'dead',
            'attempts' => 0,
            'max_attempts' => 3,
            'available_at' => $now,
            'payload' => json_encode(
                ['subject' => 'Test notification', 'body' => 'This is a dead notification'],
                JSON_UNESCAPED_UNICODE,
            ),
            'dedupe_key' => self::QUEUE_MARKER,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $db->getLastInsertID();
    }

    /** @return array<int, array<string, mixed>> */
    private function plantedQueueRows(): array
    {
        return $this->db()
            ->createCommand('SELECT [[id]] FROM {{%notification_queue}} WHERE [[dedupe_key]] = :m')
            ->bindValue(':m', self::QUEUE_MARKER)
            ->queryAll();
    }

    /**
     * Remove every queue row this file ever planted, keyed by the marker.
     *
     * Keyed rather than by insert id so cleanup still works when the test
     * failed before the id could be recorded, or when a previous run died
     * midway — and restricted to the marker so it cannot touch a real row.
     */
    private function purgePlantedQueueRows(): void
    {
        $this->db()
            ->createCommand()
            ->delete('{{%notification_queue}}', ['dedupe_key' => self::QUEUE_MARKER])
            ->execute();
    }

    private function db(): ConnectionInterface
    {
        return $this->db ??= (new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        )))->get(ConnectionInterface::class);
    }

    /**
     * A live superadmin bearer token, obtained the way the Android app gets one.
     *
     * The header is set to a placeholder *before* the login POST because the
     * global CSRF middleware skips any request carrying `Authorization: Bearer`
     * — without it the login itself is refused as a token-less form post. The
     * real token then overwrites the placeholder (haveHttpHeader keys by name)
     * for every admin call the test makes.
     */
    private function _bearerAdmin(WebTester $I): void
    {
        $I->haveHttpHeader('Authorization', 'Bearer android-admin');
        $I->sendAjaxPostRequest('/api/auth/login', [
            'identifier' => self::ADMIN_USER,
            'password' => self::ADMIN_PASSWORD,
        ]);

        $payload = $this->decode($I);
        Assert::assertTrue(
            $payload['success'] ?? false,
            'The API login must succeed, or every assertion below reads a 401: ' . (string) ($payload['message'] ?? ''),
        );

        $token = (string) ($payload['data']['token'] ?? '');
        Assert::assertNotSame('', $token, 'The login response must carry a bearer token.');

        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
    }

    private function decode(WebTester $I): array
    {
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($payload, 'The endpoint must answer with a JSON envelope.');

        return $payload;
    }
}
