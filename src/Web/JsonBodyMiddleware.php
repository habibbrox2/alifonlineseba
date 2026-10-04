<?php

declare(strict_types=1);

namespace App\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns an `application/json` request body into a PSR-7 *parsed* body.
 *
 * ## Why this exists
 *
 * Every action in this app reads its input as `$request->getParsedBody()`. That
 * works for HTML form posts because the SAPI request factory parses
 * `application/x-www-form-urlencoded` for us — but it leaves an
 * `application/json` body as an empty parse, so `getParsedBody()` returns null
 * and `(array) null` is `[]`.
 *
 * That mismatch was invisible until the push endpoints, because `api()` in
 * `resources/js/app.js` posts `body: null` everywhere else: a JSON content type
 * with no body still parses to nothing useful and the action already reads what
 * it needs from the route or the query string. `/api/push/subscribe` is the
 * first caller that sends a real object (`PushSubscription.toJSON()`), so it was
 * the first to see the empty parse — and the validation in
 * `PushApiAction::subscribe()` reported it as "A push endpoint is required."
 * for a subscription that plainly had one.
 *
 * The yii-request package ships a BodyParserMiddleware for this, but it is not
 * a dependency here and pulling it in for one content type is not worth the
 * composer surface, so this does the one case we actually send.
 *
 * ## What it deliberately does not do
 *
 * Malformed JSON is passed through untouched rather than rejected here. This
 * middleware's job is to make the body *reachable*; whether a given payload is
 * valid is the action's call, and the actions already answer that with a
 * specific 422. A middleware that 400s first would replace "A push endpoint is
 * required." with a bare parse error and hide the real shape of the problem.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($this->parse($request));
    }

    private function parse(ServerRequestInterface $request): ServerRequestInterface
    {
        // Form posts are already parsed by the SAPI factory, and re-reading the
        // stream for them would be both wasteful and wrong.
        if (!$this->isJson($request) || $request->getParsedBody() !== null) {
            return $request;
        }

        $stream = $request->getBody();

        $raw = (string) $stream;

        // __toString() leaves the cursor at EOF; put it back so anything reading
        // the body downstream still sees it.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $decoded = json_decode($raw, true);

        // A JSON scalar, a JSON list at the top level, or a parse error: none of
        // them is the (object|array) shape actions index with, so leave the
        // request alone and let the action's own validation speak.
        if (!is_array($decoded)) {
            return $request;
        }

        return $request->withParsedBody($decoded);
    }

    /**
     * `application/json`, with or without a charset, plus the `+json` structured
     * suffix (`application/merge-patch+json` and friends).
     */
    private function isJson(ServerRequestInterface $request): bool
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        return $contentType === 'application/json' || str_ends_with($contentType, '+json');
    }
}
