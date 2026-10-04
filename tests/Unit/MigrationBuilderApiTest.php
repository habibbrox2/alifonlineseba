<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;
use ReflectionClass;
use ReflectionMethod;

use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertTrue;

/**
 * A migration is only ever executed in production, on the server, against the
 * live database — so a typo in a builder call is not a test failure, it is a
 * failed deploy that leaves the site in maintenance mode.
 *
 * That is not hypothetical: `addIndex()` does not exist on MigrationBuilder
 * (`createIndex()` does), M240106 shipped with it, and the deploy died on
 * "Call to undefined method" after the columns had already been added.
 *
 * These tests read the migration sources rather than running them, so no
 * database is needed and a migration that cannot even be reached on a real
 * schema is still checked.
 */
final class MigrationBuilderApiTest extends Unit
{
    private const MIGRATION_DIR = __DIR__ . '/../../migrations';

    /**
     * Methods MigrationBuilder exposes for a migration to call.
     *
     * @return array<int, string>
     */
    private static function availableMethods(): array
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(\Yiisoft\Db\Migration\MigrationBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        return $methods;
    }

    /**
     * @return array<int, string>
     */
    private static function migrationFiles(): array
    {
        $files = glob(self::MIGRATION_DIR . '/M*.php') ?: [];
        sort($files);

        return $files;
    }

    public function testThereAreMigrationsToCheck(): void
    {
        // Guards the rest of this class: a wrong MIGRATION_DIR would make every
        // other assertion below pass by finding no files at all.
        assertNotEmpty(self::migrationFiles(), 'no migration files were found to check');
    }

    public function testEveryBuilderCallExistsOnMigrationBuilder(): void
    {
        $available = self::availableMethods();
        $unknown = [];

        foreach (self::migrationFiles() as $file) {
            $source = (string) file_get_contents($file);

            // `$b->something(` is how a migration receives the builder; the
            // arrow functions inside safe() wrappers use the same variable, so
            // they are covered by the same match.
            preg_match_all('/\$b->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches);

            foreach (array_unique($matches[1]) as $method) {
                if (!in_array($method, $available, true)) {
                    $unknown[] = sprintf('%s calls $b->%s()', basename($file), $method);
                }
            }
        }

        // Guessing a builder method is how M240106 broke a deploy, so the
        // failure has to be a test failure rather than a stack trace on the
        // server. `createIndex` is the one people reach for `addIndex` instead.
        assertTrue(
            $unknown === [],
            "MigrationBuilder has no such method:\n  " . implode("\n  ", $unknown)
            . "\nCheck the method names against vendor/yiisoft/db-migration/src/MigrationBuilder.php"
            . " (indexes are created with createIndex(), not addIndex()).",
        );
    }

    /**
     * A migration class whose name does not match its file is silently skipped
     * by the migrator, which reads migrations as a directory listing. That
     * failure mode has no output at all — the deploy just prints "Nothing to
     * migrate" — so it is worth a cheap assertion of its own.
     */
    public function testEachFileDeclaresAMatchingMigrationClass(): void
    {
        $mismatched = [];

        foreach (self::migrationFiles() as $file) {
            $expected = basename($file, '.php');
            $source = (string) file_get_contents($file);

            if (!preg_match('/^\s*(?:final\s+)?class\s+' . preg_quote($expected, '/') . '\b/m', $source)) {
                $mismatched[] = basename($file);
            }
        }

        assertTrue(
            $mismatched === [],
            "These files declare no class matching their name: " . implode(', ', $mismatched),
        );
    }

    }