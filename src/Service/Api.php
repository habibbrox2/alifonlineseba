<?php

declare(strict_types=1);

namespace App\Service;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Consistent JSON envelope: {success, message, data, errors}.
 */
final class Api
{
    public static function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function ok(mixed $data = null, string $message = ''): ResponseInterface
    {
        return self::json(['success' => true, 'message' => $message, 'data' => $data, 'errors' => []]);
    }

    public static function fail(string $message, array $errors = [], int $status = 400): ResponseInterface
    {
        return self::json(['success' => false, 'message' => $message, 'data' => $errors, 'errors' => $errors], $status);
    }

    public static function unauthorized(): ResponseInterface
    {
        return self::fail('Authentication required.', [], 401);
    }

    public static function forbidden(): ResponseInterface
    {
        return self::fail('You do not have permission to perform this action.', [], 403);
    }
}
