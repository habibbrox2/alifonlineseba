<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Notification\QueueRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class AdminNotificationsApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private QueueRepository $queue,
        private ConnectionInterface $db,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            $id = (int) $route->getArgument('id', '0');
            $ok = $this->queue->requeue($id);

            return $ok
                ? Api::ok(['message' => 'অনুরোধটি আবার কিউতে পাঠানো হয়েছে।'])
                : Api::fail('শুধুমাত্র dead-letter অনুরোধ আবার চালানো যায়।');
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');

        $where = '';
        $dbParams = [];
        if ($status !== '' && in_array($status, ['queued', 'processing', 'sent', 'dead'], true)) {
            $where = ' WHERE [[status]] = :st';
            $dbParams[':st'] = $status;
        }

        $offset = max(0, ($page - 1) * self::PER_PAGE);

        $total = (int) $this->db
            ->createCommand(
                "SELECT COUNT(*) FROM {{%notification_queue}}{$where}"
            )
            ->bindValues($dbParams)
            ->queryScalar();

        $rows = $this->db
            ->createCommand(
                "SELECT * FROM {{%notification_queue}}{$where} ORDER BY [[id]] DESC LIMIT "
                . self::PER_PAGE . " OFFSET {$offset}"
            )
            ->bindValues($dbParams)
            ->queryAll();

        return Api::ok([
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'stats' => $this->queue->stats(),
        ]);
    }
}
