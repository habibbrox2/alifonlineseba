<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use App\Service\Api;
use App\Service\RequestRowPresenter;
use stdClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/service-requests/watch — the live-status poller behind the history
 * page.
 *
 * The user leaves a request sitting on this page for minutes at a time while an
 * operator works on it. Without a push channel the only way for a status change
 * (or a freshly uploaded deliverable) to reach that open tab is for the page to
 * ask. This answers "here are the ids on screen; what do they look like now?"
 * in one round trip.
 *
 * It is batched deliberately. Polling per row would mean fifteen concurrent
 * requests every few seconds, and the batch makes the cost independent of page
 * size — one query for any number of rows.
 *
 * The ids come from the client, so `watchForUser()` scopes the query by
 * `user_id` in its own WHERE clause rather than trusting the list. A tampered
 * body asking about someone else's request returns an empty answer for that id
 * instead of their data, and the client simply keeps the row it already had.
 */
final readonly class ServiceRequestsWatchApiAction
{
    /**
     * A hard ceiling on ids per request.
     *
     * The page never sends more than `PER_PAGE`, so this only bounds a
     * hand-crafted request: it keeps a single poll from turning into an
     * arbitrarily large `IN (…)` list.
     */
    private const MAX_IDS = 50;

    public function __construct(
        private TransactionRepository $transactions,
        private RequestRowPresenter $presenter,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $body = (array) $request->getParsedBody();
        $ids = $body['ids'] ?? [];

        if (!is_array($ids)) {
            return Api::fail('Invalid request.', [], 422);
        }

        $ids = array_slice(array_values($ids), 0, self::MAX_IDS);

        if ($ids === []) {
            // Not an error: a page with no rows yet still has a poller running,
            // and an empty answer is the correct one.
            return Api::ok(['rows' => new stdClass()]);
        }

        $rows = $this->presenter->presentMany(
            $this->transactions->watchForUser($ids, $identity->id)
        );

        // Forced to an object: `presentMany()` returns a PHP array, and an empty
        // one would encode as `[]` while a populated one encodes as `{"3": …}`.
        // The client does `Object.keys(payload)`, so the shape has to be the same
        // in both cases or an all-changed page reads the answer as "nothing".
        return Api::ok(['rows' => $rows === [] ? new stdClass() : (object) $rows]);
    }
}
