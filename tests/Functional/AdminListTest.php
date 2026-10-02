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

        // The fallback is the *default* column, so compare against an explicit
        // default-sort call. Comparing against a different valid column (e.g.
        // 'username') only passed by coincidence while the table held a handful
        // of rows whose username order happened to match their id order.
        $default = $repo->paginate(1, 10, '', 'id', 'asc');
        $invalid = $repo->paginate(1, 10, '', 'DROP TABLE users', 'asc');

        assertSame(
            array_column($default['rows'], 'id'),
            array_column($invalid['rows'], 'id'),
            'Invalid sort column must fall back to default ordering.',
        );
        assertGreaterThan(0, $invalid['total']);

        // A valid column must still be honoured, otherwise the assertion above
        // would also pass if the whitelist were ignored entirely.
        $byUsername = $repo->paginate(1, 10, '', 'username', 'asc');
        $usernames = array_column($byUsername['rows'], 'username');
        $sorted = $usernames;
        sort($sorted, SORT_STRING);
        assertSame($sorted, $usernames, 'A whitelisted sort column must actually order the rows.');
    }

    public function testUserBalanceSortOrdersNumerically(): void
    {
        $repo = new UserRepository($this->db);

        $asc = $repo->paginate(1, 10, '', 'balance', 'asc');
        $desc = $repo->paginate(1, 10, '', 'balance', 'desc');

        // Both directions must be numerically ordered over the shared dev
        // database. assertSame(array_reverse(...)) would additionally demand a
        // page-size-multiple table so the two page-1 windows are exact mirrors
        // of each other — a real database with ties and a partial final page
        // does not owe us that symmetry.
        $ascBalances = array_map(static fn (array $r): float => (float) $r['balance'], $asc['rows']);
        $descBalances = array_map(static fn (array $r): float => (float) $r['balance'], $desc['rows']);

        $sorted = $ascBalances;
        sort($sorted);
        assertSame($sorted, $ascBalances, 'Ascending balance order must be numeric, not string-wise.');

        $sortedDesc = $descBalances;
        rsort($sortedDesc);
        assertSame($sortedDesc, $descBalances, 'Descending balance order must be numeric too.');
    }

    public function testTransactionSortByReference(): void
    {
        $repo = new TransactionRepository($this->db);

        $asc = $repo->all(1, 20, '', '', 'reference', 'asc');
        $refs = array_map(static fn (array $r): string => (string) $r['reference'], $asc['rows']);
        $sorted = $refs;
        sort($sorted);
        assertSame($sorted, $refs);

        // The whitelist falls back to the default column but keeps the requested
        // direction, so compare against an explicit id sort at the same direction.
        $invalid = $repo->all(1, 20, '', '', '1=1; --', 'asc');
        assertSame(
            array_column($repo->all(1, 20, '', '', 'id', 'asc')['rows'], 'id'),
            array_column($invalid['rows'], 'id'),
            'Invalid sort column must fall back to the default column.',
        );
    }
}
