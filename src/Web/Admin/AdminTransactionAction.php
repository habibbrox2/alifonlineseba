<?php

declare(strict_types=1);

namespace App\Web\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * `/admin/transactions/{id}` — a redirect to the order desk.
 *
 * The id is carried across unchanged, and it still means the same thing: the
 * order queue and the money ledger were split into two tables, but an id in a
 * URL that an operator has already bookmarked refers to an order, because that
 * is what this route only ever pointed at.
 *
 * A POST is redirected too, rather than being answered. There is nothing to do
 * here any more, and silently returning 200 to a form post would leave an
 * operator staring at a page that looks like it worked. Following the
 * redirect sends them to the desk, where the action actually lives.
 */
final readonly class AdminTransactionAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $id = (int) $route->getArgument('id', '0');

        return new \Nyholm\Psr7\Response(302, [
            'Location' => $id > 0 ? '/admin/orders/' . $id : '/admin/orders',
        ]);
    }
}
