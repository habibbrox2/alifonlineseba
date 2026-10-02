<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Service\TopupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;

/**
 * POST /recharge/cancel — the user withdraws their own pending request.
 *
 * A separate POST-only endpoint rather than a `do=cancel` branch on the form
 * action, so it can never be triggered by a GET (a prefetch, a link scraper)
 * and so the browser can confirm it with a plain dialog.
 */
final readonly class RechargeCancelAction
{
    public function __construct(
        private TopupService $topups,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $id = (int) (((array) $request->getParsedBody())['id'] ?? 0);

        [$ok, $message] = $this->topups->cancel($id, $identity->id);
        $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

        return new \Nyholm\Psr7\Response(302, ['Location' => '/recharge']);
    }
}
