<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;
use Codeception\Test\TestCaseWrapper;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Hands each test's MySQL slot back when the test is over.
 *
 * Every Functional test builds its own DI `Container` in `_before()`, which
 * owns a `ConnectionInterface` wrapping a PDO handle, and keeps it in
 * `$this->db`. The container and connection are then part of the test object
 * graph, and PHP will not free a graph that is still reachable. Which is the
 * problem: `Codeception\Suite` builds its whole test list up front and keeps it
 * for the duration of the run (`Suite::$tests`), so every test object ever
 * executed — and therefore every connection it opened — stays reachable until
 * the suite finishes.
 *
 * The upshot is that this is a *reachable* leak, not a *cyclic* one, and the
 * two need opposite treatments. `gc_collect_cycles()` reclaims objects that are
 * garbage; it does nothing here, because a test that has finished is not
 * garbage. (Measured: the collector returns ~8 objects per test and the MySQL
 * process list still climbs to 154 of `max_connections=151`.) What actually
 * frees the slot is `ConnectionInterface::close()`, which nulls the PDO handle
 * and issues `KILL CONNECTION_ID()` so the server drops it immediately rather
 * than at some unpredictable point in PHP's shutdown.
 *
 * So this does both, in the order that matters:
 *
 *  1. Close the connections the finished test is still holding. Explicit and
 *     immediate — this is what actually protects `max_connections`.
 *  2. Run the cycle collector afterwards, to sweep genuine garbage (closures
 *     captured by repositories, the dropped `Container` itself, and so on) so
 *     it cannot accumulate across a long run.
 *
 * Closing is driven by reflection over the finished test's own properties
 * rather than by each test remembering to do it. That matters because the
 * alternative is a `close()` call in 16 `_after()` methods — a convention that
 * silently stops working the first time someone adds a test and forgets, which
 * is exactly how this reached 151 in the first place. The reflective scan is
 * bounded: the test object's own class hierarchy, one level deep per property,
 * no deep object graph walk.
 *
 * `test.end` is the right hook. `Codeception\Test\Test::realRun()` dispatches it
 * *after* `runHooks('End')` has run every `_after`, so a test's own cleanup
 * still has a live, open connection while it needs one. `test.after` is too
 * early for that reason.
 *
 * Enabled globally in `codeception.yml`.
 */
final class CycleCollectorExtension extends Extension
{
    /**
     * @var array<string, string>
     */
    public static array $events = [
        Events::TEST_END => 'releaseTestResources',
    ];

    public function releaseTestResources(TestEvent $event): void
    {
        $this->closeConnectionsOf($event->getTest());

        // After closing, not before: releasing the handle is what makes the
        // rest of the graph collectable, and collecting first would just be a
        // wasted pass.
        gc_collect_cycles();
    }

    /**
     * Close every `ConnectionInterface` held as a direct property of the
     * finished test, at most once per underlying handle.
     */
    private function closeConnectionsOf(object $test): void
    {
        // Codeception wraps every PHPUnit test case; the properties we care
        // about hang off the case itself, not the wrapper.
        $case = $test instanceof TestCaseWrapper ? $test->getTestCase() : $test;

        $seen = [];
        for ($class = new \ReflectionObject($case); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                $value = $this->readProperty($case, $property);
                if (!$value instanceof ConnectionInterface) {
                    continue;
                }
                // Several properties can point at the same connection (a test
                // keeps both `$db` and a repository built on it), and close()
                // costs a round trip. One close per handle.
                $id = spl_object_id($value);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $value->close();
            }
        }
    }

    /**
     * Read a property without tripping over typed properties that were never
     * assigned, which throw on access rather than returning null.
     */
    private function readProperty(object $object, \ReflectionProperty $property): mixed
    {
        if (!$property->isInitialized($object)) {
            return null;
        }

        return $property->getValue($object);
    }
}
