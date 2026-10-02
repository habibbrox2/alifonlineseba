<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Service\Api;
use App\Service\AppReleaseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/app/version — what the installed Android app asks on launch to learn
 * whether a newer build exists.
 *
 * ## Why this is unauthenticated
 *
 * The answer is the same for every caller (the newest published release) and
 * differs only in what *this* caller reports about itself. Putting it behind
 * `ApiAuthMiddleware` would mean an app whose token has expired can never
 * discover that it must update, and the update is exactly what would fix it —
 * a lockout loop. There is nothing secret in the payload: the version, its
 * size, its notes, and a URL anyone can reach by opening /app anyway.
 *
 * ## Why it is a GET with a query parameter
 *
 * The endpoint is polled, so it must be safe and cacheable. The client's own
 * version code is a query parameter rather than a path segment so a misbehaving
 * client sending nonsense yields `version_code: 0` — "tell me the latest" —
 * instead of a 404 it cannot act on.
 */
final readonly class AppVersionApiAction
{
    public function __construct(private AppReleaseService $app) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        // Clamped rather than validated: a negative or absurd code is not an
        // attack, it is a bug in a client, and answering it with the latest
        // release is the useful response.
        $clientCode = max(0, (int) ($query['version_code'] ?? 0));

        $platform = trim((string) ($query['platform'] ?? ''));
        $platform = $platform === '' ? null : mb_substr($platform, 0, 32);

        return Api::ok($this->app->versionPayload($clientCode, $platform));
    }
}
