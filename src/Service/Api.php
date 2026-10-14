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

    /**
     * 403 for a permission denial.
     *
     * The message is optional but worth passing: the endpoints that check a
     * granular permission rather than the role have something specific to say,
     * and without this parameter they reached for fail() instead — which
     * answers 400. A client cannot tell "you are not allowed to do this" from
     * "your request was malformed", and the distinction is the whole point of
     * separating the two.
     */
    public static function forbidden(string $message = ''): ResponseInterface
    {
        return self::fail(
            $message !== '' ? $message : 'You do not have permission to perform this action.',
            [],
            403,
        );
    }
}
