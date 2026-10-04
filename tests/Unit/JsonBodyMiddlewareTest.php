<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Web\JsonBodyMiddleware;
use Codeception\Test\Unit;
use HttpSoft\Message\ServerRequest;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class JsonBodyMiddlewareTest extends Unit
{
    public function testParsesAJsonObjectBody(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '{"endpoint":"https://fcm.googleapis.com/fcm/send/abc","keys":{"p256dh":"x","auth":"y"}}',
            'application/json',
        );

        $body = (array) $this->process($request)->getParsedBody();

        assertSame('https://fcm.googleapis.com/fcm/send/abc', $body['endpoint']);
        assertSame(['p256dh' => 'x', 'auth' => 'y'], $body['keys']);
    }

    public function testParsesAJsonBodySentWithACharset(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '{"id":7}',
            'application/json; charset=UTF-8',
        );

        assertSame(['id' => 7], $this->process($request)->getParsedBody());
    }

    public function testParsesAStructuredJsonSuffix(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '{"id":7}',
            'application/merge-patch+json',
        );

        assertSame(['id' => 7], $this->process($request)->getParsedBody());
    }

    public function testLeavesAFormBodyToTheSapiFactory(): void
    {
        $request = $this->request(
            'https://allseba.online/admin/services',
            'name=Demo',
            'application/x-www-form-urlencoded',
        );

        assertNull($this->process($request)->getParsedBody());
    }

    public function testLeavesAMalformedJsonBodyForTheActionToReject(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '{"endpoint":',
            'application/json',
        );

        assertNull($this->process($request)->getParsedBody());
    }

    public function testLeavesAJsonScalarBodyUnparsed(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '"just a string"',
            'application/json',
        );

        assertNull($this->process($request)->getParsedBody());
    }

    public function testDoesNotOverwriteAnAlreadyParsedBody(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '{"endpoint":"from the raw stream"}',
            'application/json',
        )->withParsedBody(['endpoint' => 'from the factory']);

        assertSame(['endpoint' => 'from the factory'], $this->process($request)->getParsedBody());
    }

    public function testLeavesTheRawBodyReadableAfterwards(): void
    {
        $request = $this->request(
            'https://allseba.online/api/push/subscribe',
            '{"id":7}',
            'application/json',
        );

        $parsed = $this->process($request);

        assertSame('{"id":7}', (string) $parsed->getBody());
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

        (new JsonBodyMiddleware())->process($request, $handler);

        return $captured;
    }

    private function request(string $uri, string $body, string $contentType): ServerRequestInterface
    {
        return (new ServerRequest([], [], [], [], null, 'POST', $uri))
            ->withHeader('Content-Type', $contentType)
            ->withBody(Stream::create($body));
    }
}
