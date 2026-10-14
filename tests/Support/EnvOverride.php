<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Sets, clears and restores environment names for a single test.
 *
 * Tests that vary an environment value all need the same three things, and
 * getting any of them wrong makes the test lie rather than fail: a value the
 * override missed stays in place and the assertion passes for the wrong reason,
 * or a value left behind leaks into the next test that reads it.
 *
 * The subtlety is *where* a value lives. `App\Env::get()` reads `$_ENV`, then
 * `$_SERVER`, then its own defaults — and phpdotenv writes to all of
 * `$_ENV`, `$_SERVER` and the process environment at once. So clearing only
 * `$_ENV`, which is what most of these tests used to do, changed nothing at
 * all: the value simply turned up in `$_SERVER` on the next read. That is how
 * "the key renders empty" assertions went on passing while the key was still
 * there, and how `EnvironmentTest`'s "defaults to false" cases ended up
 * asserting against `.env` instead of against the default.
 *
 * Every write and every clear therefore goes to all three stores.
 */
final class EnvOverride
{
    /**
     * Records the current values of the names so they can be restored later.
     *
     * A name that is not set anywhere is recorded as null, which restore()
     * treats as "was absent" — distinct from a name set to an empty string,
     * which is a value the application reads as "unset" but must still be put
     * back exactly as it was.
     *
     * @param list<string> $names
     * @return array<string, string|null>
     */
    public static function snapshot(string ...$names): array
    {
        $saved = [];
        foreach ($names as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            $saved[$name] = $value === false ? null : (string) $value;
        }

        return $saved;
    }

    /**
     * Sets a name in every store.
     */
    public static function set(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    /**
     * Removes a name from every store, so App\Env::get() falls through to the
     * built-in default exactly as it would on a server that never set it.
     *
     * @param string ...$names
     */
    public static function clear(string ...$names): void
    {
        foreach ($names as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    /**
     * Puts back what snapshot() recorded.
     *
     * @param array<string, string|null> $saved
     */
    public static function restore(array $saved): void
    {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                self::clear($name);
            } else {
                self::set($name, $value);
            }
        }
    }

    /**
     * Runs $fn with the names cleared, then restores them whatever happens —
     * an assertion failure inside $fn must not leave the environment altered
     * for every test that runs after it.
     *
     * @param list<string> $names
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function without(array $names, callable $fn): mixed
    {
        $saved = self::snapshot(...$names);
        self::clear(...$names);

        try {
            return $fn();
        } finally {
            self::restore($saved);
        }
    }
}