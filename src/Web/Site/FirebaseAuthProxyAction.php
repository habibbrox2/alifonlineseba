<?php

declare(strict_types=1);

namespace App\Web\Site;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Proxies requests from /__/auth/* to the Firebase project's hosted auth endpoint.
 *
 * This is required because Firebase Auth blocking functions are served from
 * https://<project>.firebaseapp.com/__/auth/, and the Firebase client on the
 * page requests them from the same origin. Without a local proxy the browser
 * blocks the cross-origin request.
 */
final readonly class FirebaseAuthProxyAction
{
    private const TARGET_HOST = 'al-onlinesheba.firebaseapp.com';

    /**
     * Request headers that must never be replayed to Firebase.
     *
     * `cookie` and `authorization` are this visitor's credentials for *this*
     * origin — replaying them would hand the session cookie to a third-party
     * host on every proxied hit, for no benefit: the handler page has no use
     * for it. `content-length`, `transfer-encoding`, `expect` and `connection`
     * describe the body and the connection that curl measures for itself, and
     * a stale copy of any of them truncates the request upstream.
     */
    private const DROP_REQUEST_HEADERS = [
        'cookie',
        'authorization',
        'content-length',
        'transfer-encoding',
        'expect',
        'connection',
    ];

    /**
     * Bounds so a hung Firebase endpoint cannot pin a PHP worker forever.
     * The handler is inside a sign-in popup: a client that gives up anyway
     * beats a FPM slot that never comes back.
     */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 20;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        // The query string is not optional. Firebase hands the handler its
        // whole state in it — `mode`, `provider`, `apiKey`, and the OAuth
        // `state`/`code` Google redirects back with — and a proxy that drops
        // it returns a page that then reads a location the upstream never saw.
        $path = (string) $route->getArgument('path', '');
        $query = $request->getUri()->getQuery();
        $targetUrl = 'https://' . self::TARGET_HOST . '/__/auth/' . $path
            . ($query !== '' ? '?' . $query : '');

        $ch = curl_init($targetUrl);

        $method = strtoupper($request->getMethod());
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (in_array($method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $request->getBody());
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), self::DROP_REQUEST_HEADERS, true)) {
                continue;
            }
            if (strtolower($name) === 'host') {
                $headers[] = 'Host: ' . self::TARGET_HOST;
            } else {
                $headers[] = $name . ': ' . implode(', ', $values);
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return $this->text(502, 'Proxy Error: ' . $error);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // Upstream's own status, not a blanket 200: the handler answers 404
        // for a path this project does not serve, and swallowing that makes a
        // missing endpoint look like a working one to the SDK and to us.
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        curl_close($ch);

        $psrResponse = $this->responseFactory->createResponse($status >= 100 && $status <= 599 ? $status : 502);
        foreach ($this->parseHeaders($responseHeaders) as $name => $value) {
            if (stripos($name, 'transfer-encoding') === 0 || stripos($name, 'connection') === 0) {
                continue;
            }
            $psrResponse = $psrResponse->withHeader($name, $value);
        }

        return $psrResponse->withBody($this->streamFactory->createStream($responseBody));
    }

    /**
     * @return array<string, string[]>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $header) {
            if ($header === '' || str_contains($header, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            $headers[$name][] = $value;
        }

        return $headers;
    }

    private function text(int $status, string $body): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($body));
    }
}
