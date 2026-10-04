<?php

declare(strict_types=1);

namespace App\Web\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/admin/transactions` — a redirect to where that page now lives.
 *
 * This URL used to be two pages in a tab strip: the order queue and a mixed
 * list of every balance movement. Those are now different tables with
 * different owners, and the tab that showed "everything in one table" was the
 * reason the split was worth making — so the address redirects instead of
 * being kept as a third view that drifts back towards mixing them.
 *
 * `kind=service` goes to the order queue, because that is what a link copied
 * out of a "new order" notification carries. Anything else goes to the ledger,
 * which is what "show me the money" now means.
 *
 * Kept rather than deleted because the URL is in places this codebase does not
 * control: a bookmark in an operator's browser, a link in a chat, a `?q=`
 * search that was pasted into a ticket months ago.
 */
final readonly class AdminTransactionsAction
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        // Carry the search across, because a notification link pre-filters the
        // queue by reference and dropping it would send the operator to page
        // one of a hundred orders instead of to the order that rang.
        $target = (string) ($params['kind'] ?? '') === 'service' ? '/admin/orders' : '/admin/ledger';

        $query = [];
        if (trim((string) ($params['q'] ?? '')) !== '') {
            $query[] = 'q=' . urlencode(trim((string) $params['q']));
        }
        if ((string) ($params['status'] ?? '') !== '') {
            $query[] = 'status=' . urlencode((string) $params['status']);
        }

        if ($query !== []) {
            $target .= '?' . implode('&', $query);
        }

        return new \Nyholm\Psr7\Response(302, ['Location' => $target]);
    }
}
