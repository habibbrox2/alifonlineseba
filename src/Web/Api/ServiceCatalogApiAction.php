<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Service\Api;
use App\Service\ServiceCatalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/catalog` — the dashboard's service grid, fetched rather than
 * inlined.
 *
 * The grid is drawn client-side from JSON, and the old shape of that JSON was
 * a `data-services` attribute on the page: ~2.7 MB of table dumped into every
 * dashboard response, which (a) had to be parsed before the first card could
 * render, (b) made the page large enough to break a PCRE assertion in the
 * test suite, and (c) shipped `form_fields`, `variants` and `rules` nobody
 * asked for. This returns the eight columns a card draws — see
 * {@see ServiceCatalog} — so the same catalogue costs ~250 KB, compressed, on
 * a path of its own.
 *
 * It sits in the JSON API group, so it answers 401 to a signed-out fetch
 * instead of redirecting to the login page the way a web route would.
 */
final readonly class ServiceCatalogApiAction
{
    public function __construct(private ServiceCatalog $catalog) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return Api::ok(['services' => $this->catalog->services()]);
    }
}
