<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Notification\QueueRepository;
use App\Repository\ActivityLogRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET  /admin/notifications        queue + in-app list with health counters
 * POST /admin/notifications/{id}/retry   re-queue one dead-letter row
 */
final readonly class AdminNotificationsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private QueueRepository $queue,
        private ConnectionInterface $db,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        // POST /admin/notifications/{id}/retry
        if ($request->getMethod() === 'POST') {
            $id = (int) $route->getArgument('id', '0');
            $ok = $this->queue->requeue($id);

            $identity = $request->getAttribute('identity');
            $this->logs->create([
                'user_id' => $identity !== null ? (int) $identity->id : null,
                'action' => 'notification.retried',
                'description' => sprintf('Queue job #%d re-queued by admin (%s)', $id, $ok ? 'ok' : 'not dead'),
                'metadata' => ['queue_id' => $id],
            ]);

            $this->session->set('flash_success', $ok ? 'অনুরোধটি আবার কিউতে পাঠানো হয়েছে।' : 'শুধুমাত্র dead-letter অনুরোধ আবার চালানো যায়।');
            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/notifications']);
        }

        $status = (string) ($request->getQueryParams()['status'] ?? '');
        $rows = $this->queueRows($status);

        return $this->view->render('site/admin/notifications.twig', [
            'rows' => $rows,
            'status' => $status,
            'stats' => $this->queue->stats(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function queueRows(string $status): array
    {
        $where = '';
        $params = [];
        if ($status !== '' && in_array($status, ['queued', 'processing', 'sent', 'dead'], true)) {
            $where = ' WHERE [[status]] = :st';
            $params[':st'] = $status;
        }

        $rows = $this->db
            ->createCommand(
                'SELECT * FROM {{%notification_queue}}' . $where . ' ORDER BY [[id]] DESC LIMIT 100'
            )
            ->bindValues($params)
            ->queryAll();

        return $rows;
    }
}
