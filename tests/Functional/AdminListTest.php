<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Exception\NotFoundException;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

final class AdminListTest extends \Codeception\Test\Unit
{
    private ?ConnectionInterface $db = null;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
    }

    public function testUserSortWhitelistFallsBackForInvalidColumn(): void
    {
        $repo = new UserRepository($this->db);

        $valid = $repo->paginate(1, 10, '', 'username', 'asc');
        $invalid = $repo->paginate(1, 10, '', 'DROP TABLE users', 'asc');

        assertSame(
            array_column($valid['rows'], 'id'),
            array_column($invalid['rows'], 'id'),
            'Invalid sort column must fall back to default ordering.',
        );
        assertGreaterThan(0, $valid['total']);
    }

    public function testUserBalanceSortOrdersNumerically(): void
    {
        $repo = new UserRepository($this->db);

        $asc = $repo->paginate(1, 10, '', 'balance', 'asc');
        $desc = $repo->paginate(1, 10, '', 'balance', 'desc');

        $ascBalances = array_map(static fn (array $r): float => (float) $r['balance'], $asc['rows']);
        $descBalances = array_map(static fn (array $r): float => (float) $r['balance'], $desc['rows']);

        $sorted = $ascBalances;
        sort($sorted);
        assertSame($sorted, $ascBalances);

        assertSame(array_reverse($ascBalances), $descBalances);
    }

    public function testTransactionSortByReference(): void
    {
        $repo = new TransactionRepository($this->db);

        $asc = $repo->all(1, 20, '', '', 'reference', 'asc');
        $refs = array_map(static fn (array $r): string => (string) $r['reference'], $asc['rows']);
        $sorted = $refs;
        sort($sorted);
        assertSame($sorted, $refs);

        $invalid = $repo->all(1, 20, '', '', '1=1; --', 'asc');
        assertSame(
            array_column($asc['rows'], 'id'),
            array_column($invalid['rows'], 'id'),
            'Invalid sort column must fall back to default ordering.',
        );
    }
}
