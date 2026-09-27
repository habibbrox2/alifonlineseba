<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\TopupRepository;
use App\Service\TopupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminTopupsAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private TopupRepository $topups,
        private TopupService $service,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $do = (string) ($input['do'] ?? '');
            $topupId = (int) ($input['topup_id'] ?? 0);

            [$ok, $message] = match ($do) {
                'approve' => $this->service->approve($topupId, $identity->id),
                'reject' => $this->service->reject($topupId, $identity->id, trim((string) ($input['reason'] ?? ''))),
                default => [false, 'অজানা অ্যাকশন।'],
            };

            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('admin-topups')]);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');
        $data = $this->topups->adminList($page, self::PER_PAGE, $status);

        return $this->view->render('site/admin/topups.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'pendingCount' => $this->topups->pendingCount(),
        ]);
    }
}
