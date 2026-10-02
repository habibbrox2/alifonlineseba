<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;
use App\Service\TopupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/topups — the recharge queue.
 *
 * Row-level decisions are deliberately *not* made from here. Approving credits
 * real balance, and a one-click approve in a dense table is exactly how a
 * request gets approved before anyone has looked at the receipt. The list links
 * to the detail page, where the receipt sits next to the submitted TrxID;
 * bulk approve is the escape hatch for the case where an operator really has
 * already verified the payments out of band.
 */
final readonly class AdminTopupsAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private TopupRepository $topups,
        private TopupService $service,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();

            if ((string) ($input['do'] ?? '') === 'bulk-approve') {
                $ids = $input['ids'] ?? [];
                $result = $this->service->approveMany(is_array($ids) ? $ids : [], $identity->id);

                $message = $result['approved'] > 0
                    ? sprintf('%d টি অনুরোধ অনুমোদিত হয়েছে।', $result['approved'])
                    : 'কোনো অনুরোধ অনুমোদিত হয়নি।';
                if ($result['failed'] > 0) {
                    $message .= sprintf(' %d টি বাদ পড়েছে।', $result['failed']);
                }
                $this->session->set($result['approved'] > 0 ? 'flash_success' : 'flash_error', $message);
            }

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/topups']);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');
        $query = trim((string) ($params['q'] ?? ''));
        $sort = (string) ($params['sort'] ?? 'created_at');
        $dir = (string) ($params['dir'] ?? 'desc');

        $data = $this->topups->adminList($page, self::PER_PAGE, $status, $query, $sort, $dir);

        return $this->view->render('site/admin/topups.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'query' => $query,
            'sort' => $sort,
            'dir' => $dir,
            'stats' => $this->topups->stats(),
            'statusCounts' => $this->topups->statusCounts(),
            'pendingIds' => $this->topups->pendingIds(50),
            'methods' => SettingsRepository::METHOD_LABELS,
            'statuses' => TopupRepository::STATUSES,
        ]);
    }
}
