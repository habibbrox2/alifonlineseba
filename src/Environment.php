<?php

declare(strict_types=1);

namespace App;

/**
 * Compatibility facade over {@see Env}: the yiisoft/app template expects an
 * Environment class with appDebug()/appEnv()/prepare().
 */
final class Environment
{
    public const DEV = 'dev';
    public const TEST = 'test';
    public const PROD = 'prod';

    public static function prepare(): void
    {
        // Fail fast on a misconfigured environment.
        $env = (string) Env::get('APP_ENV', self::DEV);
        if ($env !== '' && !in_array($env, [self::DEV, self::TEST, self::PROD], true)) {
            throw new \RuntimeException(
                sprintf('APP_ENV="%s" is invalid. Valid values are "dev", "test", "prod".', $env),
            );
        }
    }

    public static function appEnv(): string
    {
        $env = (string) Env::get('APP_ENV', self::DEV);
        return $env === '' ? self::DEV : $env;
    }

    public static function appDebug(): bool
    {
        return Env::bool('APP_DEBUG', false);
    }

    public static function appC3(): bool
    {
        return Env::bool('APP_C3', false);
    }

    public static function appHostPath(): ?string
    {
        $v = Env::get('APP_HOST_PATH');
        return $v === null || $v === '' ? null : $v;
    }

    public static function isDev(): bool
    {
        return self::appEnv() === 'dev';
    }

    public static function isTest(): bool
    {
        return self::appEnv() === 'test';
    }

    public static function isProd(): bool
    {
        return self::appEnv() === 'prod';
    }
}
