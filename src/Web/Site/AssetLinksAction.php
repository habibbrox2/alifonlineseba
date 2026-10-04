<?php

declare(strict_types=1);

namespace App\Web\Site;

use App\Service\TwaAssetLinks;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * GET /.well-known/assetlinks.json — the origin's side of the TWA trust handshake.
 *
 * ## Why the path is fixed
 *
 * `/.well-known/` is reserved by RFC 8615 precisely so that a machine-readable
 * document can live at a path no site has to "make room" for. Android does
 * not read this from a redirect, a `<link>` tag, or a header: it requests the
 * literal path over HTTPS. Anything else is a 404 to the verifier, and a TWA
 * that cannot verify degrades to a Custom Tab with a browser bar.
 *
 * ## Why 404 when unconfigured, rather than an empty document
 *
 * `[]` is a *valid* answer meaning "this origin is associated with no
 * Android app". Serving it is a positive statement that no TWA may ever claim
 * the site — including one that is installed and waiting to update. A 404
 * says "nothing is configured here yet", which is the truth during setup and
 * is what an operator debugging a fresh build wants to see. The body is still
 * JSON, and says which way to fix it, so the failure is diagnosable from a
 * phone rather than from guesswork.
 *
 * ## Why this is public and outside the auth group
 *
 * It is fetched by the Android verifier on a device that has never signed in
 * and never will. The document is not secret: it is a public statement of
 * which certificates this origin trusts, and it contains no account data, no
 * identifier and no capability.
 */
final readonly class AssetLinksAction
{
    public function __construct(
        private TwaAssetLinks $links,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if (!$this->links->isConfigured()) {
            return $this->json(
                404,
                [
                    'detail' => 'No Digital Asset Links statement is configured for this origin.',
                    'fix' => 'Set TWA_ORIGIN to this site\'s https origin and TWA_FINGERPRINTS to'
                        . ' the signing certificate of the Android app, then run: php yii app:twa:fingerprints',
                ],
                'no-store',
            );
        }

        return $this->json(200, $this->links->json(), 'public, max-age=3600');
    }

    /**
     * @param array<mixed> $payload
     */
    private function json(int $status, array $payload, string $cacheControl): ResponseInterface
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', $cacheControl)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streamFactory->createStream($body === false ? '{}' : $body));
    }
}
