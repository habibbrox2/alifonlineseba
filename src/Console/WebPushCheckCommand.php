<?php

declare(strict_types=1);

namespace App\Console;

use App\Notification\Channel\WebPushChannel;
use App\Notification\Push\VapidKeys;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the Web Push setup end to end:
 *
 *   php yii app:webpush:check                             # config + keys + worker + table census
 *   php yii app:webpush:check --user=12                   # + one real push per active browser
 *   php yii app:webpush:check --endpoint=https://…        # + one real push to one browser
 *
 * This is the Web Push twin of app:fcm:check, and it exists because almost
 * every Web Push failure looks identical from the outside: no notification,
 * no error anywhere. The causes are all different — no VAPID keys, a key that
 * will not load, a missing or empty service worker, no subscriptions, the
 * account-wide switch off, a dead subscription, a 401 from the push service —
 * and each one is fixed differently. Walking the stages in order says which
 * stage broke.
 *
 * --user and --endpoint both bypass the queue on purpose: this proves the
 * encryption and the HTTP request, which is exactly what a queue-level test
 * cannot isolate.
 */
#[AsCommand('app:webpush:check', 'Verify the Web Push configuration (and optionally send a test push).')]
final class WebPushCheckCommand extends Command
{
    /**
     * @param string|null $serviceWorkerPath Overridable so the missing/empty
     *        worker paths are testable without moving the real file out from
     *        under a live site. Null means "resolve the deployed location".
     */
    public function __construct(
        private readonly WebPushChannel $webpush,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly UserRepository $users,
        private readonly ?string $serviceWorkerPath = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'Send one test message to every active browser of this user id.',
            )
            ->addOption(
                'endpoint',
                null,
                InputOption::VALUE_REQUIRED,
                'Send one test message to this stored push endpoint only.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $subject = trim((string) \App\Env::get('VAPID_SUBJECT', ''));
        $keys = VapidKeys::fromEnv();
        $io->section('Configuration');
        $io->definitionList(
            ['VAPID_SUBJECT' => $subject !== '' ? $subject : '<not set>'],
            ['Public key' => $keys !== null ? $keys->publicKey() : '<unavailable>'],
            ['TTL' => (string) \App\Env::int('WEB_PUSH_TTL', 86400) . 's'],
        );

        $error = $this->webpush->credentialsError();
        if ($error !== '') {
            $io->error($error);
            return Command::FAILURE;
        }
        $io->success('VAPID keys load as a P-256 pair — encryption and signing will work.');

        $swError = $this->serviceWorkerError();
        if ($swError !== '') {
            $io->error($swError);
            return Command::FAILURE;
        }
        $io->success('public/push-sw.js is present and non-empty — the browser can register it.');

        // The census is deliberately before any send: "there is no browser to
        // send to" and "the send failed" need different fixes, and reporting
        // a green send against zero subscriptions would hide the first.
        $summary = $this->census();
        $io->section('Subscriptions');
        $io->definitionList(
            ['Active' => (string) $summary['active'] . ' of ' . $summary['total'] . ' stored'],
            ['  of a signed-in account' => (string) $summary['owned']],
            ['  anonymous (from /app)' => (string) $summary['anonymous']],
            ['Inactive' => (string) $summary['inactive']],
        );
        if ($summary['active'] === 0) {
            $io->warning(
                'No active subscription. Open /app or /profile in a browser, allow notifications,'
                . ' and run this again — until then every real event is silently a no-op.'
            );
        }

        $userOption = trim((string) $input->getOption('user'));
        $endpointOption = trim((string) $input->getOption('endpoint'));

        if ($userOption !== '') {
            return $this->checkUser($io, $userOption);
        }
        if ($endpointOption !== '') {
            return $this->checkEndpoint($io, $endpointOption);
        }

        $io->note('No --user or --endpoint given: keys and table are proven, but nothing was sent.');
        return Command::SUCCESS;
    }

    /**
     * The service worker is the one stage that every other check waves through.
     * VAPID keys load, the table is populated, the push service answers 201 —
     * and the browser still shows nothing, because a 404 on /push-sw.js means
     * there is no worker to hand the message to. The registration URL is
     * hardcoded as /push-sw.js in push-subscribe.js and app-install.js, and it
     * has to land in the web root as-is or the scope is wrong and the worker
     * cannot claim /dashboard/orders/12.
     *
     * An empty file is called out separately from a missing one: an empty file
     * is what a botched copy leaves behind, and it parses without complaint
     * only to never call showNotification.
     */
    private function serviceWorkerError(): string
    {
        $path = $this->serviceWorkerPath ?? dirname(__DIR__, 2) . '/public/push-sw.js';
        if (!is_file($path)) {
            return 'public/push-sw.js is missing. The browser registers /push-sw.js and gets a 404,'
                . ' so every push is delivered to a worker that does not exist.';
        }
        if (filesize($path) === 0) {
            return 'public/push-sw.js is empty. Deploys that truncate it break push silently.';
        }
        return '';
    }

    /**
     * The subscription table is the one stage that can be missing entirely —
     * an un-migrated box fails here and nowhere else until the first real
     * event. Reporting it as "run the migrations" beats a PDOException.
     *
     * @return array{total: int, active: int, owned: int, anonymous: int, inactive: int}
     */
    private function census(): array
    {
        try {
            return $this->subscriptions->summary();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Could not read {{%push_subscription}}. Run: php yii migrate:up --no-interaction',
                previous: $e,
            );
        }
    }

    private function checkUser(SymfonyStyle $io, string $raw): int
    {
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            $io->error('--user must be a positive user id.');
            return Command::FAILURE;
        }
        $userId = (int) $raw;

        $user = $this->users->findById($userId);
        if ($user === null) {
            $io->error("No live user with id {$userId}.");
            return Command::FAILURE;
        }

        $io->section("Test message → user {$userId} (" . (string) ($user['username'] ?? '?') . ')');
        // Both switches are reported, because "notifications are off for this
        // account" and "this browser is off" look identical to the person
        // being notified of nothing.
        $accountOn = $this->users->isPushEnabled($userId);
        $io->text('Account-wide switch: ' . ($accountOn ? 'on' : 'OFF — send() would skip this user'));

        $subscriptions = $this->subscriptions->activeForUser($userId);
        if ($subscriptions === []) {
            $io->warning(
                $accountOn
                    ? 'This account has no active browser subscription. Subscribe from /app or /profile first.'
                    : 'The account-wide switch is off, which deactivates every browser on save.'
            );
            return Command::SUCCESS;
        }
        $io->text(count($subscriptions) . ' active browser subscription(s).');

        return $this->send($io, $subscriptions);
    }

    private function checkEndpoint(SymfonyStyle $io, string $endpoint): int
    {
        $io->section('Test message → one endpoint');
        $io->text($endpoint);

        $subscription = $this->subscriptions->activeByEndpoint($endpoint);
        if ($subscription === null) {
            // The stored keys are the whole reason this cannot just POST to
            // the endpoint: without p256dh/auth there is nothing to encrypt
            // for. An endpoint that is not in the table has to be either
            // deactivated already or a typo.
            $known = $this->subscriptions->findByEndpoint($endpoint);
            $io->error(
                $known !== null
                    ? 'That endpoint is stored but inactive — the browser will not receive anything. Re-subscribe it.'
                    : 'No stored subscription for that endpoint. Copy it from the browser devtools Push panel, or use --user.'
            );
            return Command::FAILURE;
        }

        return $this->send($io, [$subscription]);
    }

    /**
     * @param array<int, array<string, mixed>> $subscriptions
     */
    private function send(SymfonyStyle $io, array $subscriptions): int
    {
        $payload = [
            'title' => 'Web Push test',
            'body' => 'app:webpush:check টেস্ট পুশ — ' . date('Y-m-d H:i:s'),
            'data' => ['event' => 'webpush_test'],
        ];

        $failures = 0;
        foreach ($subscriptions as $subscription) {
            $label = $this->label($subscription);
            $result = $this->webpush->sendToSubscription($subscription, $payload);

            if ($result->ok) {
                $io->writeln(sprintf('  <info>OK</info>     %s (%d ms)', $label, $result->latencyMs));
                continue;
            }
            $failures++;
            $kind = $result->retryable ? 'retryable' : 'permanent';
            $io->writeln(sprintf('  <error>FAIL</error>   %s — %s', $label, $result->error));
            $io->text("             ({$kind}; " . match (true) {
                str_contains($result->error, 'expired') => 'the browser is gone — deactivate it or purge it.',
                str_contains($result->error, 'VAPID rejected') => 'our keys or subject are wrong, not this browser.',
                str_contains($result->error, 'encrypt') => 'this host could not run OpenSSL — check the binary and OPENSSL_CONF.',
                default => 'the push service or the network is having a bad day; retry is worthwhile.',
            } . ')');
        }

        if ($failures === 0) {
            $io->success(count($subscriptions) . ' test message(s) accepted by the push service.');
            return Command::SUCCESS;
        }

        $io->error("{$failures} of " . count($subscriptions) . ' test message(s) failed.');
        return Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function label(array $subscription): string
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $host = parse_url($endpoint, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? '#' . (int) ($subscription['id'] ?? 0) . ' @ ' . $host : '#' . (int) ($subscription['id'] ?? 0);
    }
}