<?php

declare(strict_types=1);

namespace App\Web\NotFound;

use App\Web\ErrorPages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fallback handler for unmatched routes — renders the friendly 404 page.
 */
final class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly \Twig\Environment $twig,
        private readonly ErrorPages $errorPages,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->errorPages->render(404);
        if ($response !== null) {
            return $response;
        }

        // Last resort: minimal HTML.
        $response = new \Nyholm\Psr7\Response(404, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->getBody()->write('<h1>404 — Page not found</h1>');
        return $response;
    }
}
