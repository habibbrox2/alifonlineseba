<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Web\ForwardedProtoMiddleware;
use Codeception\Test\Unit;
use HttpSoft\Message\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function PHPUnit\Framework\assertSame;

final class ForwardedProtoMiddlewareTest extends Unit
{
    public function testRewritesSchemeToHttpsFromTrustedProxy(): void
    {
        $request = $this->request('http://allseba.online/login', '127.0.0.1', 'https');

        $result = $this->process($request);

        assertSame('https', $result->getUri()->getScheme());
    }

    public function testKeepsSchemeWhenPeerIsNotLoopback(): void
    {
        $request = $this->request('http://allseba.online/login', '203.0.113.10', 'https');

        $result = $this->process($request);

        assertSame('http', $result->getUri()->getScheme());
    }

    public function testKeepsSchemeWhenHeaderIsPlainHttp(): void
    {
        $request = $this->request('http://allseba.online/login', '127.0.0.1', 'http');

        $result = $this->process($request);

        assertSame('http', $result->getUri()->getScheme());
    }

    public function testKeepsSchemeWhenHeaderIsAbsent(): void
    {
        $request = $this->request('http://allseba.online/login', '127.0.0.1', null);

        $result = $this->process($request);

        assertSame('http', $result->getUri()->getScheme());
    }

    public function testUsesFirstValueOfForwardedChain(): void
    {
        $request = $this->request('http://allseba.online/login', '127.0.0.1', 'https, http');

        $result = $this->process($request);

        assertSame('https', $result->getUri()->getScheme());
    }

    public function testPreservesHostAndPathWhileRewriting(): void
    {
        $request = $this->request('http://allseba.online/service-history?page=2', '127.0.0.1', 'https');

        $result = $this->process($request);

        assertSame('allseba.online', $result->getUri()->getHost());
        assertSame('/service-history', $result->getUri()->getPath());
        assertSame('page=2', $result->getUri()->getQuery());
    }

    private function process(ServerRequestInterface $request): ServerRequestInterface
    {
        $captured = null;

        $handler = new class ($captured) implements RequestHandlerInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response();
            }
        };

        (new ForwardedProtoMiddleware())->process($request, $handler);

        return $captured;
    }

    private function request(string $uri, string $remoteAddr, ?string $forwardedProto): ServerRequestInterface
    {
        $request = new ServerRequest(['REMOTE_ADDR' => $remoteAddr], [], [], [], null, 'GET', $uri);

        return $forwardedProto === null
            ? $request
            : $request->withHeader('X-Forwarded-Proto', $forwardedProto);
    }
}
