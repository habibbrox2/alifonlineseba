<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Service\Api;
use App\Service\StatusPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /api/service-requests/{id} — the deep-link target for push
 * notifications. Scoped by user_id in the WHERE clause (audit security #5):
 * another user's request gets the same 404 a missing one does, so the
 * endpoint cannot be used to discover which ids exist.
 */
final readonly class ServiceRequestDetailApiAction
{
    public function __construct(private ServiceOrderRepository $orders) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $row = $this->orders->findOwned((int) $route->getArgument('id', '0'), $identity->id);
        if ($row === null) {
            return Api::fail('অনুরোধটি পাওয়া যায়নি।', [], 404);
        }

        $metadata = ServiceOrderRepository::metadata($row);

        return Api::ok([
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'service' => $row['service_name'] ?? ($metadata['service_name'] ?? null),
            'amount' => (float) $row['amount'],
            'status' => (string) $row['status'],
            'status_label' => StatusPresenter::label((string) $row['status']),
            'result' => $metadata['result'] ?? null,
            'error' => $metadata['error'] ?? null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ]);
    }
}
