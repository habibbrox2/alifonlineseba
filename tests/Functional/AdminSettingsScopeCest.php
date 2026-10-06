<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Site settings are an owner decision, and the page must behave like one.
 *
 * `/admin/settings` used to live inside the plain admin group, so every
 * operator with a staff account could retake the recharge rules — the minimum
 * and maximum everyone is asked to pay, which wallet numbers the money goes
 * to, the referral bonuses — while the pages that *review* those rules
 * (/topups, /recharges/{id}) stayed where they were. The move puts the page in
 * the super-admin group and hides its links, but neither of those is the test:
 * a link hidden by a template is still reachable by typing the URL, and a
 * route moved by hand can be moved back by a later commit that does not know.
 *
 * So what is asserted here is the whole seam, in both directions:
 *
 *   - an admin is turned away from GET *and* POST — the write matters more
 *     than the read, because a refused GET with a working POST is a page that
 *     still moves money — and is told why rather than handed a bare redirect;
 *   - the rest of the panel still works for him, so "guarded" has not become
 *     "broken";
 *   - the super-admin can read and save it, which is what proves the route
 *     moved and was not simply lost;
 *   - and the sidebar offers the page only to the person who can use it, so
 *     the panel does not advertise a door that is locked.
 *
 * The `site_setting` rows are live site configuration, so every test that can
 * write them snapshots the row first — value *and* `updated_by`, and deleting
 * it again if it did not exist — following the same rule as
 * `OrderWindowTest::_after()`.
 */
final class AdminSettingsScopeCest
{
    /** Only ever used for throwaway accounts, deleted again before returning. */
    private const PASSWORD = 'Scope5Settings!';

    /** The refusal message SuperAdminMiddleware flashes; the user must see it. */
    private const REFUSAL = 'এই পাতাটি শুধুমাত্র সুপারএডমিন দেখতে ও পরিবর্তন করতে পারবেন।';

    private ?ConnectionInterface $connection = null;

    /** @var int[] */
    private array $createdUserIds = [];

    /**
     * An admin must be turned away from the page *and* shown the door.
     *
     * The redirect alone would pass even if the sidebar still linked to it —
     * every click would then dead-end into a redirect, which looks broken
     * rather than restricted. And /admin/topups is in the test because the
     * requirement is a split one: reviewing a recharge stays an admin's job,
     * only the *rules* move up, so a guard that swallowed both would be the
     * wrong fix passing for the right one.
     */
    public function anAdminIsTurnedAwayAndKeepsHisOwnWork(FunctionalTester $tester): void
    {
        $userId = null;
        try {
            [$userId, $username] = $this->makeUser('scoped', 'admin');
            $cookie = $this->signIn($tester, $username);

            $denied = $this->send($tester, new ServerRequest(uri: '/admin/settings', cookieParams: $cookie));
            assertSame(302, $denied->getStatusCode(), 'A typed URL must land on the same guard as a clicked link.');
            assertTrue(
                str_ends_with($denied->getHeaderLine('Location'), '/admin'),
                'Refusal is a redirect to the panel, not a 403 — the operator still has a job to do.'
            );

            $panel = $this->visit($tester, '/admin', $cookie);
            assertSame(200, $panel->getStatusCode());
            $html = (string) $panel->getBody();

            assertStringContainsString(
                self::REFUSAL,
                $html,
                'A bare redirect leaves the operator to guess which page is missing and why.',
            );
            assertStringNotContainsString(
                'href="/admin/settings"',
                $html,
                'The sidebar must not offer a page that instantly refuses him.',
            );
            assertStringContainsString(
                'href="/admin/topups"',
                $html,
                'The positive control: an ordinary admin\'s own money work must still be linked, or the guard swallowed too much.',
            );

            $refused = (int) $this->db()
                ->createCommand(
                    'SELECT COUNT(*) FROM {{%activity_log}}'
                    . ' WHERE [[user_id]] = :u AND [[action]] = :a'
                )
                ->bindValue(':u', $userId)
                ->bindValue(':a', 'admin.super_denied')
                ->queryScalar();
            assertSame(1, $refused, 'A refusal must be attributable: the log row is how an attempt is noticed.');

            $topups = $this->visit($tester, '/admin/topups', $cookie);
            assertSame(
                200,
                $topups->getStatusCode(),
                'Reviewing a recharge is still an admin task — only the rules moved to the owner page.',
            );
        } finally {
            foreach ($this->createdUserIds as $id) {
                $this->dropUser($id);
            }
            $this->createdUserIds = [];
        }
    }

    /**
     * The POST half: refused before it can write, proven by the row itself.
     *
     * A guard applied only to GET would be decorative — the settings are
     * changed by POST, and an admin who never sees the form can still send the
     * request by hand. Asserting the stored value rather than the status code
     * is what makes this fail if the guard ever stops running, because then
     * the redirect still happens and the write still lands.
     */
    public function anAdminsSettingsPostNeverReachesTheDatabase(FunctionalTester $tester): void
    {
        $userId = null;
        $snapshot = $this->snapshotSettings();
        $before = $this->settings()->get('site_tagline');
        try {
            [$userId, $username] = $this->makeUser('scopedpost', 'admin');
            $cookie = $this->signIn($tester, $username);

            $fields = [
                '_csrf' => $this->csrf($this->visit($tester, '/admin', $cookie)),
                'site_tagline' => 'taken over by an ordinary admin',
            ];
            $posted = $this->post($tester, '/admin/settings', $fields, $cookie);

            assertSame(302, $posted->getStatusCode());
            assertTrue(
                !str_ends_with($posted->getHeaderLine('Location'), '/admin/settings'),
                'The save endpoint must not redirect to itself for someone who was not allowed to save.',
            );
            assertSame(
                $before,
                $this->settings()->get('site_tagline'),
                'The refusal has to be about the database too: the guard must run before the action writes.',
            );
        } finally {
            $this->restoreSettings($snapshot);
            foreach ($this->createdUserIds as $id) {
                $this->dropUser($id);
            }
            $this->createdUserIds = [];
        }
    }

    /**
     * The other direction of the same move: the page still exists and still
     * saves, for the one role that owns it.
     *
     * Without this, the two tests above are satisfied by a route that was
     * deleted or a guard that blocks everyone — a broken page is a passing
     * test otherwise. The save goes through the real POST with a body built
     * from the current settings so that only the tagline differs: an unchecked
     * checkbox in the body would otherwise be written back as "off" across the
     * whole form, which is a way of breaking live configuration from a test.
     */
    public function aSuperAdminCanReadAndSaveTheSettingsPage(FunctionalTester $tester): void
    {
        $userId = null;
        $snapshot = $this->snapshotSettings();
        $original = $this->settings()->all();
        try {
            [$userId, $username] = $this->makeUser('ownerset', Identity::ROLE_SUPERADMIN);
            $cookie = $this->signIn($tester, $username);

            $form = $this->visit($tester, '/admin/settings', $cookie);
            assertSame(200, $form->getStatusCode(), 'The owner must reach the page the admin is refused.');
            $html = (string) $form->getBody();
            assertStringContainsString('action="/admin/settings"', $html, 'The form must be the real one.');
            assertStringContainsString(
                'href="/admin/settings"',
                (string) $this->visit($tester, '/admin', $cookie)->getBody(),
                'The sidebar link belongs to the super-admin, and must be there for him.',
            );

            $fields = $original;
            $fields['_csrf'] = $this->csrf($form);
            $fields['site_tagline'] = 'owner saved this';

            $posted = $this->post($tester, '/admin/settings', $fields, $cookie);
            assertSame(302, $posted->getStatusCode(), 'A valid save redirects back to itself.');
            assertSame('/admin/settings', $posted->getHeaderLine('Location'));
            assertSame(
                'owner saved this',
                $this->settings()->get('site_tagline'),
                'What the owner posts is what the site serves — otherwise the guard has hidden a dead form.',
            );
        } finally {
            $this->restoreSettings($snapshot);
            foreach ($this->createdUserIds as $id) {
                $this->dropUser($id);
            }
            $this->createdUserIds = [];
        }
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * A throwaway account in a chosen role.
     *
     * @return array{0: int, 1: string}
     */
    private function makeUser(string $tag, string $role): array
    {
        $users = new UserRepository($this->db());
        $username = 'scope_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 6);

        $id = $users->create([
            'username' => $username,
            'phone' => '1' . substr(md5($username), 0, 9),
            'email' => null,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
        ]);
        $this->createdUserIds[] = $id;

        return [$id, $username];
    }

    /**
     * Deleting in FK order: the activity log rows this very test writes
     * reference the user.
     */
    private function dropUser(int $id): void
    {
        $db = $this->db();
        foreach (['{{%activity_log}}', '{{%notification}}', '{{%notification_queue}}'] as $table) {
            $db->createCommand("DELETE FROM {$table} WHERE [[user_id]] = :u")
                ->bindValue(':u', $id)
                ->execute();
        }
        $db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        $this->createdUserIds = array_values(array_diff($this->createdUserIds, [$id]));
    }

    /**
     * Opens the login form and posts it, the way a browser does.
     *
     * The body has to be a stream (a string body is read as a *file path*) and
     * the session id is regenerated by the login itself, so the cookie comes
     * from the response to the POST rather than from the form that preceded it.
     *
     * @return array<string, string>
     */
    private function signIn(FunctionalTester $tester, string $username): array
    {
        $form = $this->send($tester, new ServerRequest(uri: '/login'));
        assertSame(200, $form->getStatusCode());

        $cookie = $this->sessionCookies($form->getHeaderLine('Set-Cookie'));
        assertNotEmpty($cookie, 'No session cookie, so no request can be made as the same visitor.');

        $found = preg_match('/name="_csrf" value="([^"]+)"/', (string) $form->getBody(), $m);
        assertSame(1, $found, 'The login form must carry a CSRF token.');

        $fields = ['_csrf' => $m[1], 'identifier' => $username, 'password' => self::PASSWORD];
        $posted = $this->post($tester, '/login', $fields, $cookie);

        assertSame(
            302,
            $posted->getStatusCode(),
            "Login failed for {$username}: the page re-rendered instead of redirecting.",
        );

        // Newest first, so the regenerated session id wins over the pre-login one.
        return $this->sessionCookies($posted->getHeaderLine('Set-Cookie')) + $cookie;
    }

    /**
     * The global CSRF middleware answers 422 before routing runs, so the token
     * has to be scraped from a rendered page — the meta tag the layout ships.
     *
     * @param ResponseInterface $page
     */
    private function csrf(ResponseInterface $page): string
    {
        $found = preg_match('/<meta name="_csrf" content="([^"]+)">/', (string) $page->getBody(), $m);
        assertSame(1, $found, 'Every page in the shared layout carries the token.');
        return $m[1];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $cookie
     */
    private function post(FunctionalTester $tester, string $uri, array $fields, array $cookie): ResponseInterface
    {
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        return $this->send($tester, new ServerRequest(
            uri: $uri,
            method: 'POST',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            cookieParams: $cookie,
            parsedBody: $fields,
            body: $stream,
        ));
    }

    /** @param array<string, string> $cookie */
    private function visit(FunctionalTester $tester, string $uri, array $cookie): ResponseInterface
    {
        return $this->send($tester, new ServerRequest(uri: $uri, cookieParams: $cookie));
    }

    /**
     * Each sendRequest() boots a fresh application runner inside this same PHP
     * process, so the session has to be closed in between or the next request
     * inherits the one still open here — carrying nothing, CSRF included.
     */
    private function send(FunctionalTester $tester, ServerRequest $request): ResponseInterface
    {
        $response = $tester->sendRequest($request);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $response;
    }

    /**
     * Set-Cookie header line to the request-side cookie array: attributes
     * (Domain, Path, HttpOnly) are not request data, only name=value pairs.
     *
     * @return array<string, string>
     */
    private function sessionCookies(string $setCookie): array
    {
        $cookies = [];
        foreach (preg_split('/,(?=\s*[A-Za-z0-9_]+=)/', $setCookie) ?: [] as $chunk) {
            $pair = strtok(trim($chunk), ';');
            if ($pair === false || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            if (str_contains($name, 'SESSION')) {
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }

    /**
     * Every `site_setting` row as it is — value *and* updater — keyed.
     *
     * One key is not enough: `AdminSettingsAction` saves through `putMany()`,
     * which stamps `updated_by` across *every* key in the form (the body is
     * built from `all()` precisely so no checkbox flips). Restoring only the
     * field the test changed would leave the other rows pointing at a user
     * about to be deleted, and the FK `site_setting.updated_by → user.id`
     * then refuses the delete — which is exactly how this test first learned
     * that it had to look at the whole table.
     *
     * @return array<string, array{id: int, setting_value: string, updated_by: int|null}>
     */
    private function snapshotSettings(): array
    {
        /** @var array<int, array{setting_key: string, id: int|string, setting_value: string, updated_by: int|string|null}> $rows */
        $rows = $this->db()->createCommand(
            'SELECT [[setting_key]], [[id]], [[setting_value]], [[updated_by]] FROM {{%site_setting}}'
        )->queryAll();

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[(string) $row['setting_key']] = [
                'id' => (int) $row['id'],
                'setting_value' => (string) $row['setting_value'],
                'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            ];
        }
        return $snapshot;
    }

    /**
     * Put the table back: same values, same updaters, and no rows that did
     * not exist — a save materialises rows for keys the database had never
     * stored, and leaving them behind would make "the site has always had
     * this setting" look true forever after.
     *
     * @param array<string, array{id: int, setting_value: string, updated_by: int|null}> $snapshot
     */
    private function restoreSettings(array $snapshot): void
    {
        $db = $this->db();
        $current = $db->createCommand('SELECT [[setting_key]] FROM {{%site_setting}}')->queryColumn();

        foreach ($current as $key) {
            if (!isset($snapshot[$key])) {
                $db->createCommand()->delete('{{%site_setting}}', ['setting_key' => (string) $key])->execute();
                continue;
            }

            $row = $snapshot[$key];
            $db->createCommand()->update('{{%site_setting}}', [
                'setting_value' => $row['setting_value'],
                'updated_by' => $row['updated_by'],
            ], ['id' => $row['id']])->execute();
        }
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->db());
    }

    private function db(): ConnectionInterface
    {
        if ($this->connection === null) {
            $container = new Container(ContainerConfig::create()->withDefinitions(
                require codecept_root_dir() . 'config/common/di/db.php',
            ));
            $this->connection = $container->get(ConnectionInterface::class);
        }
        return $this->connection;
    }
}
