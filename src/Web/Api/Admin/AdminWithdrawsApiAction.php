<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\AdminWithdrawRepository;
use App\Service\AdminWithdrawService;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /api/admin/withdraws — the super-admin's payout queue.
 * POST /api/admin/withdraws/{id} — the decision desk: approve, reject,
 * claim or release one payout.
 *
 * The decision itself lives in AdminWithdrawService, exactly as for the
 * web desk (App\Web\Admin\AdminWithdrawsAction): the same self-approval
 * refusal, the same idempotency gate, the same refund-before-stamp order.
 * This action is only the JSON skin over it, so the web panel and the
 * Android app cannot drift apart.
 */
final readonly class AdminWithdrawsApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private AdminWithdrawRepository $withdraws,
        private AdminWithdrawService $service,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->isSuperAdmin()) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $route, $identity);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');
        $q = trim((string) ($params['q'] ?? ''));

        $data = $this->withdraws->adminList($page, self::PER_PAGE, $status, $q);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'q' => $q,
            'stats' => $this->withdraws->stats(),
        ]);
    }

    /**
     * do=approve | do=reject | do=claim | do=release — the same set the
     * web desk offers, so an Android super-admin can do everything the
     * browser panel can.
     */
    private function handlePost(array $input, CurrentRoute $route, Identity $identity): ResponseInterface
    {
        $id = (int) $route->getArgument('id', '0');
        $row = $this->withdraws->findById($id);
        if ($row === null) {
            return Api::fail('উত্তোলন অনুরোধ পাওয়া যায়নি।', [], 404);
        }

        [$ok, $message] = match ((string) ($input['do'] ?? '')) {
            'approve' => $this->service->approve($id, $identity),
            'reject' => $this->service->reject($id, $identity, trim((string) ($input['reason'] ?? ''))),
            'claim' => $this->service->claim($id, $identity),
            'release' => $this->service->release($id, $identity),
            default => [false, 'অজানা অ্যাকশন।'],
        };

        if (!$ok) {
            return Api::fail($message);
        }

        // The decision changed the row; hand the caller the fresh state so
        // the queue on their screen agrees with the database.
        return Api::ok([
            'withdraw' => $this->withdraws->findById($id) ?? $row,
            'message' => $message,
        ]);
    }
}
