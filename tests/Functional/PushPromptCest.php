<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Notification\Push\VapidKeys;
use App\Tests\Support\FunctionalTester;
use App\Web\View\PushViewInjection;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;

use function PHPUnit\Framework\assertFalse;
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
 * So the tests below are mostly about the seams, and the rules that only the
 * browser can enforce — never prompting on load, never re-prompting after a
 * dismissal — are asserted at the source level instead, with the reasoning
 * written down next to the assertion.
 */
final class PushPromptCest
{
    /** The partial's root marker; also what the JS looks the prompt up by. */
    private const MARKER = 'data-push-prompt';

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    // No _before()/_after(): a Cest's are not invoked without the actor
    // parameter, and the one test that needs VAPID keys loads them itself.

    public function anOrdinaryPageCarriesTheKeyAndTheOffer(FunctionalTester $tester): void
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

    // ---- helpers ------------------------------------------------------------

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
