<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Notification\Push\VapidKeys;
use App\Repository\UserRepository;
use App\Tests\Support\FunctionalTester;
use App\Web\View\PushViewInjection;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Permission is asked for from any page, not only from `/app`.
 *
 * Until now `/app` was the single place a browser could be handed the VAPID key
 * and asked for permission, so somebody who never downloaded the APK was never
 * asked — and then never heard that their own order had finished. The fix is a
 * prompt that rides the shared layout, which means the thing under test is a
 * *contract across four files*: the layout has to include the partial, the
 * partial has to ship hidden, the layout has to render the key for pages whose
 * action knows nothing about push, and the guard that keeps the prompt off the
 * pages which already own the decision has to match the markup those pages
 * really carry. A rename in any one of them produces a site that either never
 * asks or asks twice, and neither shows up in the browser by accident.
 *
 * "Any page" then had to be narrowed to *any signed-in page*, because a
 * permission granted while signed out attaches to no account and can only ever
 * feed the anonymous broadcast list — so the ask itself became a signed-in
 * question while the repair stayed universal. Paired with it is the standing
 * reminder for an account whose push switch is off: a one-off offer cannot do
 * that job, because a dismissal and a fortnight of silence both bury it.
 *
 * So the tests below are mostly about the seams, and the rules that only the
 * browser can enforce — never prompting on load, never re-prompting after a
 * dismissal — are asserted at the source level instead, with the reasoning
 * written down next to the assertion.
 */
final class PushPromptCest
{
    /** The partial's root marker; also what the JS looks the prompt up by. */
    private const MARKER = 'data-push-prompt';

    /** The standing reminder's marker; `shouldAsk()` skips the page it is on. */
    private const REMINDER = 'data-push-reminder';

    /** Only ever used for throwaway accounts, deleted again before returning. */
    private const PASSWORD = 'Phase5Push!';

    private ?ConnectionInterface $connection = null;

    /** @var int[] */
    private array $createdUserIds = [];

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    // No _before()/_after(): a Cest's are not invoked without the actor
    // parameter, and the one test that needs VAPID keys loads them itself.

    /**
     * The markup and the key ship everywhere, *including* for guests.
     *
     * A guest page is a place where the offer is never shown, but not a place
     * where the module may be absent: `syncExistingSubscription()` is what
     * repairs a rotated endpoint for an anonymous subscriber, and it runs from
     * any page. Rendering the partial only for signed-in visitors would buy a
     * tidier guest page at the cost of that repair, so the partial renders and
     * says who it is talking to instead — see the next test.
     */
    public function anOrdinaryPageCarriesTheKeyAndTheMarkup(FunctionalTester $tester): void
    {
        $this->loadVapidKeys();
        try {
            $key = VapidKeys::fromEnv()?->publicKey() ?? '';
            assertTrue($key !== '', 'Precondition: VAPID keys in .env.');

            // Two pages that have nothing to do with push. If the prompt only
            // worked on one of them the site-wide claim is false.
            foreach (['/', '/login'] as $uri) {
                $response = $tester->sendRequest(new ServerRequest(uri: $uri));
                $body = $this->body($response->getStatusCode(), $response->getBody()->getContents(), $uri);

                assertStringContainsString(
                    '<meta name="vapid-public-key" content="' . $key . '">',
                    $body,
                    "{$uri} must render the public key, or the prompt has nothing to subscribe with."
                );
                assertStringContainsString(self::MARKER, $body, "{$uri} must carry the prompt.");
                assertStringContainsString(
                    'initPushPrompt',
                    $body,
                    "{$uri} must run the module; the partial alone does nothing."
                );
            }
        } finally {
            $this->restoreEnv($this->previousEnv);
        }
    }

    /**
     * The load path must not be able to open a permission dialog.
     *
     * Firefox refuses a prompt without a user gesture, and on some Chrome
     * builds the dialog is modal — a box that appears because someone navigated
     * is the fastest route to a permanent denial. Two things enforce that here:
     * the partial ships `hidden` and is revealed only after `shouldAsk()` says
     * so, and the only call into the subscribing code sits behind the Allow
     * click. The `hidden` attribute is asserted as *rendered markup*, so a
     * template edit that drops it fails here rather than in a visitor's face.
     */
    public function theOfferArrivesHiddenAndOnlyAsksOnAClick(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/'));
        $body = $this->body($response->getStatusCode(), $response->getBody()->getContents(), '/');

        assertTrue(
            (bool) preg_match('/<div[^>]*\bdata-push-prompt\b[^>]*\bhidden\b/', $body),
            'The prompt root must be rendered hidden: an offer, never a dialog.'
        );

        // Comments stripped first: the file's own header explains at length
        // that it never calls requestPermission, and asserting on the raw text
        // would fail on the explanation.
        $source = $this->withoutComments($this->source('resources/js/push-prompt.js'));

        assertStringNotContainsString(
            'requestPermission',
            $source,
            'Only push-subscribe.js may ask for permission, and only behind a click.'
        );

        $click = strpos($source, "allow.addEventListener('click'");
        $ask = strpos($source, 'await subscribe(key)');
        assertTrue($click !== false, 'The Allow button must be wired to something.');
        assertTrue(
            $ask !== false && $ask > $click,
            'subscribe() must be reached only from the Allow click handler, never on load.',
        );
    }

    /**
     * The pages that already own the decision must stay out of it.
     *
     * `/app` and `/profile` have their own push card, so the guard in
     * `shouldAsk()` skips them — and it does that by looking for the same
     * attributes those templates carry. Nothing at runtime links the two, so a
     * rename in either place produces two competing prompts for one decision,
     * which looks like a bug report and is not one. Assert the selector and
     * the markup agree, in both directions.
     */
    public function thePagesThatAlreadyOwnTheDecisionAreMarkedSoThePromptSkipsThem(
        FunctionalTester $tester
    ): void {
        $guard = $this->withoutComments($this->source('resources/js/push-prompt.js'));
        assertStringContainsString('[data-push-root], [data-push-settings]', $guard);

        // /app is unauthenticated, so the real render is assertable here.
        $response = $tester->sendRequest(new ServerRequest(uri: '/app'));
        $body = $this->body($response->getStatusCode(), $response->getBody()->getContents(), '/app');
        assertStringContainsString(
            'data-push-root',
            $body,
            '/app owns the decision, so it must still be marked the way the guard expects.'
        );

        // /profile needs a session, so the template is read directly: the
        // marker is static markup, and rendering the page would test the
        // session instead of the contract.
        assertStringContainsString(
            'data-push-settings',
            $this->source('resources/views/site/account/profile.twig'),
            'The profile push card is the other page that owns this decision.',
        );
    }

    /**
     * The endpoint the prompt posts to has to accept a call from an
     * ordinary, signed-out page.
     *
     * A 422 is the CSRF middleware refusing a request that carried no token —
     * which is the proof wanted here. 401 or 302 would mean the prompt's very
     * first call bounces off the auth wall on every public page, and the
     * failure would be invisible until somebody tried to turn notifications on
     * while signed out. No row is written, so nothing needs cleaning up.
     */
    public function theSubscribeEndpointIsReachableWithoutSigningIn(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->jsonRequest(
            '/api/push/subscribe',
            '{"endpoint":"https://push.example.invalid/wpwatch-prompt","keys":{"p256dh":"x","auth":"y"}}',
        ));

        assertSame(
            422,
            $response->getStatusCode(),
            'Expected the CSRF gate (422), not an auth redirect — the prompt posts from public pages.',
        );
    }

    /**
     * A deployment with no keys must not render an offer it can never keep.
     */
    public function withoutKeysTheLayoutRendersAnEmptyKey(): void
    {
        $saved = $this->withoutVapidKeys();
        try {
            $params = (new PushViewInjection())->getCommonParameters();
        } finally {
            $this->restoreEnv($saved);
        }

        assertSame('', $params['vapidPublicKey']);
        assertFalse(
            $params['vapidEnabled'],
            'An empty key must be reported as "no push", not as a key that is merely late.',
        );
    }

    /**
     * The module is served from `public/assets`, which is a build product.
     *
     * `public/assets/**` is gitignored, so the copied file cannot be asserted
     * to exist — a fresh checkout that never ran the build would fail for a
     * reason that has nothing to do with the code. What *is* tracked is the
     * promise that the build copies this module to the exact path the layout
     * imports, and that promise is what silently breaks the prompt.
     */
    public function theBuildCopiesTheModuleToThePathTheLayoutImports(): void
    {
        assertStringContainsString(
            'push-prompt.js',
            $this->source('scripts/sync-js.js'),
            'sync-js.js must copy push-prompt.js, or the import 404s and no page ever asks.',
        );
        assertStringContainsString(
            "from '{{ asset('/assets/js/push-prompt.js') }}'",
            $this->source('resources/views/layouts/base.twig'),
        );
    }

    // ---- Who the offer is for -----------------------------------------------

    /**
     * The offer belongs to people who are signed in, and the page has to say so.
     *
     * A permission granted from a public page is recorded against no account:
     * `push_subscription.user_id` stays NULL, so it can only ever reach the
     * anonymous broadcast list, and the person who granted it silently hears
     * nothing about their own order. Asking a signed-out visitor for that is a
     * question with no useful answer, so the server states the audience and the
     * browser obeys it.
     *
     * Both halves are asserted on real renders — one as a guest, one signed in
     * — because the attribute is the whole contract: a template that stops
     * writing it does not break anything loudly, it just quietly goes back to
     * asking strangers.
     */
    public function theOfferIsMarkedForGuestsAndForTheSignedInAlike(FunctionalTester $tester): void
    {
        $this->loadVapidKeys();
        $userId = null;
        try {
            [$id, $username] = $this->makeUser('audience', true);
            $userId = $id;

            $guest = $this->visit($tester, '/', []);
            assertSame(200, $guest->getStatusCode());
            assertStringContainsString(
                self::MARKER . ' data-signed-in="0"',
                $this->promptTag((string) $guest->getBody()),
                'A signed-out visitor must be marked as one.',
            );

            $cookie = $this->signIn($tester, $username);

            $signedIn = $this->visit($tester, '/dashboard', $cookie);
            assertSame(200, $signedIn->getStatusCode());
            assertStringContainsString(
                self::MARKER . ' data-signed-in="1"',
                $this->promptTag((string) $signedIn->getBody()),
                'A signed-in visitor must be marked as one — this is what lifts the offer.',
            );
        } finally {
            $this->restoreEnv($this->previousEnv);
            if ($userId !== null) {
                $this->dropUser($userId);
            }
        }
    }

    /**
     * The two ends of the `data-signed-in` contract have to keep agreeing.
     *
     * Nothing links the attribute the template writes to the one the module
     * reads, which is the same hazard as `[data-push-settings]` above: rename
     * one and the site goes back to asking guests, which no error reports.
     * The guard is checked to sit *inside* `shouldAsk()` rather than merely to
     * exist, because a check anywhere else is decoration.
     */
    public function theGuestRuleLivesInTheDecisionAndReadsTheMarkup(): void
    {
        $source = $this->withoutComments($this->source('resources/js/push-prompt.js'));

        assertStringContainsString(
            "root.dataset.signedIn === '1'",
            $source,
            'The module must read the attribute the partial renders.',
        );
        assertStringContainsString(
            'data-signed-in=',
            $this->source('resources/views/partials/push-prompt.twig'),
            'The partial must render the attribute the module reads.',
        );

        $guard = $this->functionBody($source, 'export function shouldAsk');
        assertStringContainsString(
            '!isSignedIn()',
            $guard,
            'The sign-in rule belongs in shouldAsk() — the function that decides whether the bar is revealed.',
        );
    }

    // ---- The standing reminder ---------------------------------------------

    /**
     * Somebody who turned notifications off has to be told about it on every
     * page, until they turn it back on.
     *
     * The one-off prompt cannot do this: dismiss it, or wait out the fortnight,
     * and a person who has already decided never learns that their order
     * finished. So this one has no dismissal and no expiry — it simply stops
     * rendering when `push_enabled` goes back to 1, which the test proves by
     * flipping the switch through the real POST rather than by writing to the
     * column, so the whole `/profile` → account → layout path is the thing
     * under test.
     */
    public function theReminderStaysUpUntilTheSwitchIsTurnedBackOn(FunctionalTester $tester): void
    {
        $this->loadVapidKeys();
        $userId = null;
        try {
            [$id, $username] = $this->makeUser('reminder', false);
            $userId = $id;

            $cookie = $this->signIn($tester, $username);

            $off = $this->visit($tester, '/dashboard', $cookie);
            assertSame(200, $off->getStatusCode());
            $html = (string) $off->getBody();
            assertStringContainsString(
                self::REMINDER,
                $html,
                'An account with push switched off must be reminded on every page.',
            );
            assertStringContainsString(
                'href="/profile"',
                $html,
                'The reminder is only useful if it names the page where the switch is.',
            );

            $this->flipAccountSwitch($tester, $cookie, '1');

            $on = $this->visit($tester, '/dashboard', $cookie);
            assertSame(
                0,
                preg_match('/' . self::REMINDER . '/', (string) $on->getBody()),
                'The reminder must go away the moment the switch is back on — otherwise it is nagging about nothing.',
            );
        } finally {
            $this->restoreEnv($this->previousEnv);
            if ($userId !== null) {
                $this->dropUser($userId);
            }
        }
    }

    /**
     * The control for the test above, and the two places the reminder must not
     * appear.
     *
     * Without the "push already on" half, a partial that never rendered at all
     * would pass the previous test as soon as the switch was flipped. It also
     * pins the two exclusions, because each is a bug the other direction:
     * on `/profile` the reminder is the person being told to do the thing they
     * are already standing on the page to do, and a guest has no switch at all.
     */
    public function theReminderIsOnlyForAnAccountThatHasNotBeenTold(FunctionalTester $tester): void
    {
        $this->loadVapidKeys();
        $offId = null;
        $onId = null;
        try {
            [$off, $offUsername] = $this->makeUser('off', false);
            $offId = $off;
            [$on, $onUsername] = $this->makeUser('on', true);
            $onId = $on;

            // Guest first: no account, so no switch to be reminded about.
            $guest = $this->visit($tester, '/', []);
            assertSame(200, $guest->getStatusCode());
            assertSame(
                0,
                preg_match('/' . self::REMINDER . '/', (string) $guest->getBody()),
                'A signed-out visitor has no push switch, so a reminder is meaningless.',
            );

            $onCookie = $this->signIn($tester, $onUsername);
            $onPage = $this->visit($tester, '/dashboard', $onCookie);
            assertSame(
                0,
                preg_match('/' . self::REMINDER . '/', (string) $onPage->getBody()),
                'An account that already has push on must not be reminded — the same reason /profile does not nag.',
            );

            $offCookie = $this->signIn($tester, $offUsername);
            $profile = $this->visit($tester, '/profile', $offCookie);
            assertSame(200, $profile->getStatusCode());
            assertStringContainsString(
                self::MARKER,
                (string) $profile->getBody(),
                'Sanity: the profile page does render the push card that owns this decision.',
            );
            assertSame(
                0,
                preg_match('/' . self::REMINDER . '/', (string) $profile->getBody()),
                '/profile carries the switch itself, so it does not also need a bar pointing at it.',
            );
        } finally {
            $this->restoreEnv($this->previousEnv);
            foreach ([$offId, $onId] as $id) {
                if ($id !== null) {
                    $this->dropUser($id);
                }
            }
        }
    }

    /**
     * "Permanent" is the whole requirement, so the bar must not be dismissible.
     *
     * A dismiss control here would reintroduce exactly what the one-off prompt
     * does wrong — a single click and the reminder is gone for good — and the
     * person who has already turned notifications off is the one case where
     * they most need to be nagged. Nothing may therefore write the dismissal
     * stamp for this element, and the layout must not offer to.
     */
    public function theReminderCannotBeDismissed(): void
    {
        $partial = $this->withoutTwigComments($this->source('resources/views/partials/push-reminder.twig'));

        assertSame(
            0,
            preg_match('/<button|dismiss/i', $partial),
            'The reminder must render no dismiss control: it is the version without a way out.',
        );
        assertStringContainsString(
            "['/profile', '/app']",
            $partial,
            'The two pages that already own the switch must be excluded from it.',
        );

        // One ask per decision: while the standing bar is up, the dismissible
        // offer must stay down, or the bottom of the screen competes with the
        // top of it.
        assertStringContainsString(
            '[data-push-reminder]',
            $this->withoutComments($this->source('resources/js/push-prompt.js')),
            'shouldAsk() must skip a page that is already carrying the reminder.',
        );
    }

    /**
     * The half of "permission na dile ba notification bondho thakle" the
     * account switch cannot cover.
     *
     * `push_enabled = 0` is what the first pair of tests covers, but a browser
     * that was told "block" has a switch still on and a subscription it will
     * never deliver to — and unlike the offer, it can never be re-asked, so if
     * this bar does not exist that visitor hears nothing ever again. The
     * server cannot see the browser's answer, so the bar ships `hidden` on
     * every eligible page and the JS unhides it only when permission really is
     * denied; asserting the rendered markup pins both halves of that handoff.
     *
     * The exclusions are re-checked here because each name a different bug:
     * a second bar on top of the account one is noise about the same broken
     * pipe, and `/profile` already carries the push card that owns the fix.
     */
    public function theDeniedPermissionBarShipsHiddenAndAlone(FunctionalTester $tester): void
    {
        $this->loadVapidKeys();
        $offId = null;
        $onId = null;
        try {
            [$on, $onUsername] = $this->makeUser('denied', true);
            $onId = $on;
            [$off, $offUsername] = $this->makeUser('deniedoff', false);
            $offId = $off;

            // Push on: the account bar must be silent, the denied bar present
            // and hidden until the browser says no.
            $onCookie = $this->signIn($tester, $onUsername);
            $dashboard = $this->visit($tester, '/dashboard', $onCookie);
            assertSame(200, $dashboard->getStatusCode());
            $html = (string) $dashboard->getBody();

            assertTrue(
                (bool) preg_match('/<div[^>]*\bdata-push-denied\b[^>]*\bhidden\b/', $html),
                'The denied bar must ship rendered but hidden: only the browser knows the answer.',
            );
            assertSame(
                0,
                preg_match('/' . self::REMINDER . '/', $html),
                'Exactly one standing bar: with the switch on, the account reminder has nothing to say.',
            );

            // The two bars never coexist — the partial is an if/else.
            $profile = $this->visit($tester, '/profile', $onCookie);
            assertSame(200, $profile->getStatusCode());
            assertSame(
                0,
                preg_match('/data-push-denied/', (string) $profile->getBody()),
                '/profile owns the switch and the card; it does not need the browser warning either.',
            );

            // Control: switch off takes the account branch, so no denied bar.
            $offCookie = $this->signIn($tester, $offUsername);
            $offPage = $this->visit($tester, '/dashboard', $offCookie);
            assertStringContainsString(self::REMINDER, (string) $offPage->getBody());
            assertSame(
                0,
                preg_match('/data-push-denied/', (string) $offPage->getBody()),
                'An account whose switch is off is told about the switch first — one bar, one fix.',
            );
        } finally {
            $this->restoreEnv($this->previousEnv);
            foreach ([$onId, $offId] as $id) {
                if ($id !== null) {
                    $this->dropUser($id);
                }
            }
        }
    }

    /**
     * The reveal rule itself, asserted at the source.
     *
     * Whether a browser is denied is unknowable to the server and unreachable
     * by a request test — there is no way to make a headless Chromium answer
     * "block" — so the condition lives in JS and is pinned here: the bar is
     * looked up by its own marker, it is unhidden only for a real refusal, and
     * the unhide is reachable from the entry point that already runs on every
     * page. Getting this wrong in either direction fails a visitor:
     * over-revealing nags people whose push works, under-revealing abandons
     * the one case that can never be re-asked.
     */
    public function theDeniedBarIsRevealedOnlyWhenTheBrowserSaidNo(): void
    {
        $source = $this->withoutComments($this->source('resources/js/push-prompt.js'));

        assertTrue(
            (bool) preg_match('/export function revealDeniedReminder\s*\(/', $source),
            'The reveal must be exported so it is part of the module contract, not an inline handler.',
        );

        $body = $this->functionBody($source, 'export function revealDeniedReminder');
        assertStringContainsString(
            "'[data-push-denied]'",
            $body,
            'The reveal must address the partial by its marker, or a rename silently disables it.',
        );
        assertStringContainsString(
            "Notification.permission !== 'denied'",
            $body,
            'Only a refusal may show the bar: "default" is still an open offer, "granted" is a working pipe.',
        );
        assertStringContainsString(
            'bar.hidden = false;',
            $body,
            'The bar ships hidden and must be revealed, not injected — the markup is the server\'s job.',
        );

        $entry = $this->functionBody($source, 'export async function initPushPrompt');
        assertStringContainsString(
            'revealDeniedReminder();',
            $entry,
            'Nothing else calls it, so an entry point that skips it leaves the bar hidden forever.',
        );
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * `{# … #}` blocks dropped, markup left intact.
     *
     * The partial argues at length about why it cannot be dismissed, so a rule
     * about the word "dismiss" has to be checked against the markup rather than
     * against the prose explaining it.
     */
    private function withoutTwigComments(string $source): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }

    /**
     * The opening tag of the prompt element, and nothing else.
     *
     * The sign-in attribute has to be asserted on *that* element. Searching the
     * whole page would also match a comment or a `data-signed-in` belonging to
     * some other component, and would pass on a prompt that had lost the
     * attribute altogether.
     */
    private function promptTag(string $html): string
    {
        $found = preg_match('/<div[^>]*\bdata-push-prompt\b[^>]*>/', $html, $m);
        assertSame(1, $found, 'The page must render the prompt element.');
        return $m[0];
    }

    /**
     * The body of a top-level function, from its signature to the brace that
     * closes it.
     *
     * Used to prove a rule sits in the function that decides — `shouldAsk()`
     * revealing the bar — rather than somewhere in the file that merely reads
     * like it does.
     */
    private function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        assertTrue($start !== false, "{$signature} is missing from the module.");
        $end = strpos($source, "\n}", $start);
        assertTrue($end !== false, "{$signature} has no closing brace.");
        return substr($source, $start, $end - $start);
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

    /**
     * A throwaway account with a known password.
     *
     * The sign-in rule is the only way to assert it honestly: the alternative
     * is to reach into the session, which would test the harness rather than
     * the page. Removed again by {@see dropUser()}.
     *
     * @return array{0: int, 1: string}
     */
    private function makeUser(string $tag, bool $pushEnabled): array
    {
        $users = new UserRepository($this->db());
        $username = 'phase5_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 6);

        $id = $users->create([
            'username' => $username,
            'phone' => '1' . substr(md5($username), 0, 9),
            'email' => null,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
        ]);
        $users->setPushEnabled($id, $pushEnabled);

        $this->createdUserIds[] = $id;
        return [$id, $username];
    }

    /**
     * Deleting in FK order: activity log and notifications reference the user.
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
     * Two things are easy to get wrong and neither announces itself: the body
     * has to be a stream (a string body is read as a *file path*), and the
     * session id is regenerated by the login itself, so the cookie has to come
     * from the response to the POST rather than from the form that preceded it.
     *
     * @return array<string, string> cookies for every request that follows
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
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        $posted = $this->send($tester, new ServerRequest(
            uri: '/login',
            method: 'POST',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            cookieParams: $cookie,
            parsedBody: $fields,
            body: $stream,
        ));

        assertSame(
            302,
            $posted->getStatusCode(),
            "Login failed for {$username}: the page re-rendered instead of redirecting.",
        );

        // Newest first, so the regenerated session id wins over the pre-login one.
        return $this->sessionCookies($posted->getHeaderLine('Set-Cookie')) + $cookie;
    }

    /**
     * The account-wide push switch, through the endpoint that owns it.
     *
     * @param array<string, string> $cookie
     */
    private function flipAccountSwitch(FunctionalTester $tester, array $cookie, string $enabled): void
    {
        $form = $this->send($tester, new ServerRequest(uri: '/profile', cookieParams: $cookie));
        $found = preg_match('/name="_csrf" value="([^"]+)"/', (string) $form->getBody(), $m);
        assertSame(1, $found, 'The profile page must carry a CSRF token.');

        $fields = ['_csrf' => $m[1], 'do' => 'push', 'enabled' => $enabled];
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        $posted = $this->send($tester, new ServerRequest(
            uri: '/profile',
            method: 'POST',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            cookieParams: $cookie,
            parsedBody: $fields,
            body: $stream,
        ));
        assertSame(302, $posted->getStatusCode(), 'The switch is a POST that redirects back.');
    }

    /**
     * @param array<string, string> $cookie
     */
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
     * Set-Cookie header line to the request-side cookie array.
     *
     * Attributes (Domain, Path, HttpOnly) are not request data; only the
     * name=value pairs of the session cookie survive.
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

    private function body(int $status, string $body, string $uri): string
    {
        assertSame(200, $status, "{$uri} must render for the prompt to be reachable at all.");
        return $body;
    }

    private function source(string $path): string
    {
        $contents = file_get_contents(codecept_root_dir() . $path);
        assertTrue($contents !== false, "Cannot read {$path}.");
        return $contents;
    }

    /**
     * Comment lines dropped, code left intact.
     *
     * The JS files here argue at length in their headers, and a rule like
     * "must not call requestPermission" has to be checked against the code
     * rather than against the prose explaining why it does not.
     */
    private function withoutComments(string $source): string
    {
        $kept = [];
        foreach (explode("\n", $source) as $line) {
            $trimmed = ltrim($line, " \t");
            if ($trimmed === '' || $trimmed[0] === '*' || $trimmed[0] === '/' || $trimmed[0] === '#') {
                continue;
            }
            $kept[] = $line;
        }
        return implode("\n", $kept);
    }

    /**
     * A JSON POST.
     *
     * The body has to be a stream, not a string: `HttpSoft\Message\ServerRequest`
     * reads a string body as a *file path* and throws when there is no such
     * file, which is a confusing way to learn that a test posted nothing.
     */
    private function jsonRequest(string $uri, string $json): ServerRequest
    {
        $stream = new Stream();
        $stream->write($json);

        return new ServerRequest(
            method: 'POST',
            uri: $uri,
            headers: ['Content-Type' => 'application/json'],
            body: $stream,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function withoutVapidKeys(): array
    {
        $saved = [
            'VAPID_SUBJECT' => $_ENV['VAPID_SUBJECT'] ?? null,
            'VAPID_PRIVATE_KEY' => $_ENV['VAPID_PRIVATE_KEY'] ?? null,
        ];
        unset($_ENV['VAPID_SUBJECT'], $_ENV['VAPID_PRIVATE_KEY']);
        return $saved;
    }

    /**
     * @param array<string, string|null> $saved
     */
    private function restoreEnv(array $saved): void
    {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    /**
     * The test bootstrap deliberately does not load `.env`, so without this the
     * key renders empty and the assertions about it pass vacuously — a test that
     * cannot fail is worse than no test, because it reads like coverage.
     *
     * @see \App\Tests\Functional\WebPushOptOutTest::loadVapidKeys()
     */
    private function loadVapidKeys(): void
    {
        $this->previousEnv = [
            'VAPID_SUBJECT' => $_ENV['VAPID_SUBJECT'] ?? null,
            'VAPID_PRIVATE_KEY' => $_ENV['VAPID_PRIVATE_KEY'] ?? null,
        ];

        if (VapidKeys::fromEnv() !== null) {
            return;
        }

        /** @var array<string, string> $values */
        $values = \Dotenv\Dotenv::createArrayBacked(dirname(__DIR__, 2))->safeLoad();
        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if (isset($values[$name]) && $values[$name] !== '') {
                $_ENV[$name] = $values[$name];
            }
        }

        assertTrue(
            VapidKeys::fromEnv() !== null,
            'This test cannot assert anything about push without VAPID keys in .env.'
                . ' Generate a pair with: php scripts/generate-vapid-keys.php mailto:ops@example.com'
        );
    }
}
