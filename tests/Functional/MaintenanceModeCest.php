<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\ApiTokenRepository;
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

/**
 * Maintenance mode: the owner closes the site from /admin/settings, and can
 * always open it again.
 *
 * The switch is only worth having if two things are true at once, and each is
 * asserted here rather than assumed:
 *
 *   - it actually closes the site — every public path answers 503 with the
 *     message the owner typed, and not merely the home page but the API too,
 *     because an Android user must not be told "success" by a server that is
 *     refusing the work;
 *   - it never closes the door behind the person who turned it on. That is
 *     the failure this feature can uniquely have: a maintenance switch with no
 *     way back is worse than no switch at all. So sign-in stays open, the
 *     admin panel stays open, and the admin API the Android app talks to stays
 *     open — the last one by bearer token, which is the identity the app
 *     actually has.
 *
 * Every test writes live `site_setting` rows through the real form, so the
 * table is snapshotted before each one — value *and* `updated_by`, and rows
 * that did not exist are deleted again afterwards, because `putMany()`
 * materialises rows for keys the database had never stored. Same rule as
 * `AdminSettingsScopeCest` and `OrderWindowTest`.
 */
final class MaintenanceModeCest
{
    /** Only ever used for throwaway accounts, deleted again before returning. */
    private const PASSWORD = 'Maint3nance!Mode';

    /** The sentence the owner types, and what a visitor must then read. */
    private const MESSAGE = 'সার্ভার আপগ্রেড চলছে — বিকেল ৫টা পর্যন্ত বন্ধ।';

    private ?ConnectionInterface $connection = null;

    /** @var int[] */
    private array $createdUserIds = [];

    /** @var array<string, array{id: int, setting_value: string, updated_by: int|null}> */
    private array $settingsSnapshot = [];

    public function _before(): void
    {
        $this->settingsSnapshot = $this->snapshotSettings();
        // Every test starts from a site that is open, whatever an earlier run
        // left behind. The snapshot above is what puts it back.
        $this->settings()->putMany(['maintenance_enabled' => '0'], null);
    }

    public function _after(): void
    {
        $this->restoreSettings($this->settingsSnapshot);

        foreach ($this->createdUserIds as $id) {
            $db = $this->db();
            // FK order: tokens and logs point at the row about to go.
            $db->createCommand()->delete('{{%api_token}}', ['user_id' => $id])->execute();
            foreach (['{{%activity_log}}', '{{%notification}}', '{{%notification_queue}}'] as $table) {
                $db->createCommand("DELETE FROM {$table} WHERE [[user_id]] = :u")
                    ->bindValue(':u', $id)
                    ->execute();
            }
            $db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->createdUserIds = [];

        $this->db()->close();
        // A fresh container — and a fresh PDO connection — per test, so the
        // closed handle must not be the one the next test inherits.
        $this->connection = null;
    }

    /**
     * The whole loop through the real form: open, switch on, closed.
     *
     * The baseline 200 is not padding — without it, a gate that answered 503
     * to everything would pass every assertion below, and the test would be
     * proving "the site is down" rather than "the owner closed it".
     */
    public function switchingItOnFromSettingsClosesTheSiteForVisitors(FunctionalTester $tester): void
    {
        [, $username] = $this->makeUser('mnt_on', Identity::ROLE_SUPERADMIN);

        assertSame(200, $this->get($tester, '/')->getStatusCode(), 'The site must be open before anything is switched on.');

        $cookie = $this->signIn($tester, $username);
        $fields = $this->settingsFields($tester, $cookie);
        $fields['maintenance_enabled'] = '1';
        $fields['maintenance_message'] = self::MESSAGE;

        $posted = $this->post($tester, '/admin/settings', $fields, $cookie);
        assertSame(302, $posted->getStatusCode(), 'A valid save redirects back to itself.');
        assertSame('/admin/settings', $posted->getHeaderLine('Location'));
        assertSame(
            '1',
            $this->settings()->get('maintenance_enabled'),
            'The switch has to be stored — a page that only says it saved would leave the site open.',
        );

        $home = $this->get($tester, '/');
        assertSame(503, $home->getStatusCode(), 'The home page is the page a visitor asks for first.');
        $html = (string) $home->getBody();
        assertStringContainsString(
            self::MESSAGE,
            $html,
            'The owner wrote a reason; a page that shows generic copy instead has read the wrong key.',
        );
        assertStringContainsString(
            'noindex, nofollow',
            $html,
            'A page that is only up for a while must not be indexed while it is.',
        );
        assertStringContainsString(
            'setTimeout',
            $html,
            'The visitor should not have to press reload — the deploy gate auto-retries too.',
        );
        assertSame('60', $home->getHeaderLine('Retry-After'), 'Caches and the app must be told how long to wait.');
        assertStringContainsString('no-store', $home->getHeaderLine('Cache-Control'), '…and must not keep it after the switch is off.');

        $dashboard = $this->get($tester, '/dashboard');
        assertSame(
            503,
            $dashboard->getStatusCode(),
            'Redirecting a guest to /login would be the site working: routing must never run.',
        );

        // Sign-in stays open, which is the whole reason the owner is not
        // locked out — and a session obtained *after* the switch is on must
        // reach the panel, not this page.
        $fresh = $this->signIn($tester, $username);
        assertSame(200, $this->get($tester, '/admin/settings', $fresh)->getStatusCode());
    }

    /** The API half: the same closure, in the envelope machine clients parse. */
    public function apiClientsGetAJson503RatherThanHtmlPage(FunctionalTester $tester): void
    {
        [$userId] = $this->makeUser('mnt_api', Identity::ROLE_SUPERADMIN);
        $this->settings()->putMany([
            'maintenance_enabled' => '1',
            'maintenance_message' => self::MESSAGE,
        ], null);

        $closed = $this->get($tester, '/api/dashboard');
        assertSame(503, $closed->getStatusCode());
        assertSame('60', $closed->getHeaderLine('Retry-After'));
        assertSame(
            'application/json; charset=UTF-8',
            $closed->getHeaderLine('Content-Type'),
            'An HTML page in an API response is a parse error waiting to happen.',
        );

        $payload = json_decode((string) $closed->getBody(), true);
        assertSame(JSON_ERROR_NONE, json_last_error(), 'The body must be JSON — an HTML page here is a parse error in the app.');
        assertSame(false, $payload['success'] ?? null, 'the envelope must say no, not yes');
        assertSame(
            self::MESSAGE,
            $payload['message'] ?? null,
            'The app shows this string to the user, so it is the owner\'s message, not a hardcoded one.',
        );
        assertSame([], $payload['errors'] ?? null);

        // A *valid* user token does not open the API: the exemption is the
        // admin route group, not the credential.
        $userToken = (new ApiTokenRepository($this->db()))->issue($userId, 'codecept')['token'];
        $withToken = $this->get($tester, '/api/dashboard', [], ['Authorization' => 'Bearer ' . $userToken]);
        assertSame(503, $withToken->getStatusCode(), 'A signed-in user is still a visitor as far as maintenance goes.');
    }

    /**
     * The way back: panel, sign-in and the Android app's own endpoints.
     *
     * The guest assertions are the other side of the same coin — passing the
     * admin paths through must grant nothing to someone who is not staff, or
     * "the admin stays reachable" would mean "the admin is public".
     */
    public function theAdminSurfacesStayReachableWhileTheSiteIsClosed(FunctionalTester $tester): void
    {
        [$superId, $superName] = $this->makeUser('mnt_sa', Identity::ROLE_SUPERADMIN);
        [, $adminName] = $this->makeUser('mnt_ad', 'admin');
        $this->settings()->putMany([
            'maintenance_enabled' => '1',
            'maintenance_message' => self::MESSAGE,
        ], null);

        // A guest gets sent to the login form — not to the panel, and not to
        // the maintenance page either: the open path is open, the guarded one
        // stays guarded.
        $guest = $this->get($tester, '/admin');
        assertSame(302, $guest->getStatusCode(), 'Passing a path through must not pass the role check.');
        assertStringContainsString('/login', $guest->getHeaderLine('Location'));

        $superCookie = $this->signIn($tester, $superName);
        $panel = $this->get($tester, '/admin/settings', $superCookie);
        assertSame(200, $panel->getStatusCode(), 'The owner must reach the switch that turns this off.');
        assertStringContainsString(
            'মেইনটেন্যান্স মোডে আছে',
            (string) $panel->getBody(),
            'The page must admit the site is closed, or the operator will wonder why the phone is ringing.',
        );

        // An ordinary admin keeps working — the panel is the operator's desk,
        // and closing it would stop the approvals maintenance is often *for*.
        $adminCookie = $this->signIn($tester, $adminName);
        assertSame(200, $this->get($tester, '/admin', $adminCookie)->getStatusCode());

        // The Android super-admin authenticates with a bearer token, never a
        // session. If that route were closed, the app could open the site but
        // never close it again.
        $token = (new ApiTokenRepository($this->db()))->issue($superId, 'codecept')['token'];
        $api = $this->get($tester, '/api/admin/settings', [], ['Authorization' => 'Bearer ' . $token]);
        assertSame(200, $api->getStatusCode(), 'The app must be able to reach the settings endpoint that flips this back.');
        $payload = json_decode((string) $api->getBody(), true);
        assertSame('1', $payload['data']['maintenance_enabled'] ?? null, '…and it must see the switch is on.');
    }

    /** The off half of the request, through the same form the owner uses. */
    public function uncheckingTheBoxOpensTheSiteAgain(FunctionalTester $tester): void
    {
        [, $username] = $this->makeUser('mnt_off', Identity::ROLE_SUPERADMIN);
        $this->settings()->putMany(['maintenance_enabled' => '1'], null);
        assertSame(503, $this->get($tester, '/')->getStatusCode(), 'A closed site must be provably closed first.');

        $cookie = $this->signIn($tester, $username);
        $fields = $this->settingsFields($tester, $cookie);
        // Browsers omit an unchecked box from the body entirely, so the box is
        // dropped rather than posted as "0" — that is the shape a real
        // unchecking produces, and the one that has to work.
        unset($fields['maintenance_enabled']);

        $posted = $this->post($tester, '/admin/settings', $fields, $cookie);
        assertSame(302, $posted->getStatusCode());
        assertSame(
            '0',
            $this->settings()->get('maintenance_enabled'),
            'An absent checkbox must read as off — otherwise the site can never be opened again from the form.',
        );
        assertSame(200, $this->get($tester, '/')->getStatusCode(), 'The site must be serving again.');
    }

    // ---- helpers ------------------------------------------------------------

    /** A throwaway account in a chosen role. @return array{0: int, 1: string} */
    private function makeUser(string $tag, string $role): array
    {
        $users = new UserRepository($this->db());
        $username = 'mnt_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 6);

        $id = $users->create([
            'username' => $username,
            'phone' => '3' . substr(md5($username), 0, 9),
            'email' => null,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
        ]);
        $this->createdUserIds[] = $id;

        return [$id, $username];
    }

    /**
     * The current settings plus a fresh CSRF token, exactly as the form posts
     * them, so that saving changes one field rather than half the table.
     *
     * Both maintenance fields are asserted to be *in* that form while we are
     * here. The checkbox matters most: an unchecked box is absent from a real
     * POST, and the save path forces every absent checkbox to "off" — so a
     * form that forgot to render it would switch maintenance off on every
     * unrelated save, silently, which is exactly the bug this feature can
     * have and still look like it works.
     *
     * @param array<string, string> $cookie
     * @return array<string, string>
     */
    private function settingsFields(FunctionalTester $tester, array $cookie): array
    {
        $page = $this->get($tester, '/admin/settings', $cookie);
        assertSame(200, $page->getStatusCode(), 'The owner must reach the settings page.');

        $html = (string) $page->getBody();
        assertStringContainsString('name="maintenance_enabled"', $html, 'The switch must be on the page the owner uses.');
        assertStringContainsString('name="maintenance_message"', $html, '…and so must the box that explains the closure.');

        $fields = $this->settings()->all();
        $fields['_csrf'] = $this->csrf($page);

        return $fields;
    }

    /** Opens the login form and posts it, the way a browser does. @return array<string, string> */
    private function signIn(FunctionalTester $tester, string $username): array
    {
        $form = $this->get($tester, '/login');
        assertSame(200, $form->getStatusCode());

        $cookie = $this->sessionCookies($form->getHeaderLine('Set-Cookie'));
        assertNotEmpty($cookie, 'No session cookie, so no request can be made as the same visitor.');
        assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', (string) $form->getBody(), $m), 'The login form must carry a CSRF token.');

        $posted = $this->post($tester, '/login', [
            '_csrf' => $m[1],
            'identifier' => $username,
            'password' => self::PASSWORD,
        ], $cookie);

        assertSame(302, $posted->getStatusCode(), "Login failed for {$username}: the page re-rendered instead of redirecting.");

        // Newest first, so the regenerated session id wins over the pre-login one.
        return $this->sessionCookies($posted->getHeaderLine('Set-Cookie')) + $cookie;
    }

    /** @param array<string, string> $cookie @param array<string, string> $headers */
    private function get(FunctionalTester $tester, string $uri, array $cookie = [], array $headers = []): ResponseInterface
    {
        return $this->send($tester, new ServerRequest(uri: $uri, headers: $headers, cookieParams: $cookie));
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

    /** @param ResponseInterface $page */
    private function csrf(ResponseInterface $page): string
    {
        assertSame(1, preg_match('/<meta name="_csrf" content="([^"]+)">/', (string) $page->getBody(), $m), 'Every page in the shared layout carries the token.');

        return $m[1];
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

    /** Set-Cookie line to the request-side cookie array: attributes are not request data. @return array<string, string> */
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
     * @return array<string, array{id: int, setting_value: string, updated_by: int|null}>
     */
    private function snapshotSettings(): array
    {
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
     * Put the table back: same values, same updaters, and no rows that did not
     * exist — a save materialises rows for keys the database had never stored,
     * and leaving them behind would make "this site has always had a
     * maintenance switch" look true forever after.
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
