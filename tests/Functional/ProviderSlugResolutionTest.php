<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\Tests\Support\Database;
use App\Tests\Support\TestGraph;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

/**
 * Which mock provider each service slug resolves to.
 *
 * Resolution is `str_contains` over the slug, so a new slug can be swallowed
 * by an older, broader token (an earlier `certificate` check would have taken
 * every pourashava/union service, and every unmatched slug silently falls back
 * to the NID form). This is the mapping's regression guard: the new services
 * land on their own provider, and the seeded slugs keep the one they had.
 */
final class ProviderSlugResolutionTest extends \Codeception\Test\Unit
{
    private ServiceManager $manager;

    protected function _before(): void
    {
        $db = Database::connection();
        $users = new UserRepository($db);

        $this->manager = new ServiceManager(
            new ServiceRepository($db),
            TestGraph::orders($db),
            TestGraph::ledger($db, $users),
            $users,
            new ActivityLogRepository($db),
            new NotificationRepository($db),
            TestGraph::notify($db, $users, new \App\Repository\SettingsRepository($db)),
            OrderWindowService::alwaysOpen(),
        );
    }

    /**
     * @return array<int, array{string, string}>
     */
    public static function providerOfSlugs(): array
    {
        return [
            // Page-two services — the ones this mapping was extended for.
            ['sample-illustrator-file', 'mock-digital-file'],
            ['sample-word-file', 'mock-digital-file'],
            ['necessary-fonts', 'mock-digital-file'],
            ['bangla-text-converter', 'mock-bangla-converter'],
            ['pourashava-trade-license', 'mock-local-gov'],
            ['union-trade-license', 'mock-local-gov'],
            ['pourashava-certificate', 'mock-local-gov'],
            ['union-certificate', 'mock-local-gov'],

            // Existing slugs — a broader new token must not have captured them.
            ['nid-make', 'mock-nid-make'],
            ['nid-copy', 'mock-nid'],
            ['voter-search-by-name', 'mock-voter'],
            ['tin-certificate-full', 'mock-tin'],
            ['birth-certificate-clone', 'mock-nid'],
            ['teletalk-payment', 'mock-teletalk-payment'],
            ['mrp-epassport-sb-copy', 'mock-nid'],
        ];
    }

    /**
     * @dataProvider providerOfSlugs
     */
    public function testSlugResolvesToItsProvider(string $slug, string $expectedKey): void
    {
        $provider = $this->manager->providerFor(['slug' => $slug, 'service_type' => 'mock']);

        assertSame($expectedKey, $provider?->key(), 'slug: ' . $slug);
    }

    public function testAnUnmatchedSlugStillFallsBackToTheNidProvider(): void
    {
        $provider = $this->manager->providerFor(['slug' => 'something-brand-new', 'service_type' => 'mock']);

        assertSame('mock-nid', $provider?->key());
    }

    public function testANonMockServiceResolvesToNoProviderAtAll(): void
    {
        $provider = $this->manager->providerFor(['slug' => 'pourashava-certificate', 'service_type' => 'manual']);

        assertSame(null, $provider);
    }
}
