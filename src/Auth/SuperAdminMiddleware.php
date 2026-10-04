<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;

/**
 * The super-admin guard: staff pages that move money between people.
 *
 * `AdminMiddleware` answers "is this account allowed in the admin area at all".
 * This answers a narrower and more dangerous question — "is this account
 * allowed to hand out money, manage staff, and correct the books" — and it
 * deliberately re-reads the account from the database instead of trusting the
 * identity on the request.
 *
 * That re-read is the point. An admin demoted five minutes ago still holds a
 * session whose `identity` attribute says `admin`, and the withdrawal queue is
 * exactly the page they would be looking at. Reading the live row costs one
 * indexed lookup and closes the window between "the super-admin demoted them"
 * and "their session expires".
 *
 * Refusal is a redirect to the admin dashboard with a message, not a 403. The
 * person is a legitimate operator — they are simply not the person who decides
 * payouts — so an error page would be the wrong answer for them and would hide
 * the fact that the rest of the panel is one click away.
 */
final readonly class SuperAdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SessionInterface $session,
        private UserRepository $users,
        private ActivityLogRepository $logs,
        private UrlGeneratorInterface $url,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($identity instanceof Identity && $identity->isSuperAdmin()) {
            return $handler->handle($request);
        }

        // Confirm against the live row: the session's role is a snapshot taken
        // at sign-in, and authority is exactly the thing that can be revoked
        // without the holder being told.
        $fresh = $identity instanceof Identity ? $this->users->findById($identity->id) : null;
        if ($fresh !== null && (string) $fresh['role'] === Identity::ROLE_SUPERADMIN) {
            return $handler->handle($request);
        }

        $this->session->set('flash_error', 'এই পাতাটি শুধুমাত্র সুপারএডমিন দেখতে ও পরিবর্তন করতে পারবেন।');

        $this->logs->create([
            'user_id' => $identity instanceof Identity ? $identity->id : null,
            'action' => 'admin.super_denied',
            'description' => 'Super-admin-only page requested',
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'metadata' => ['path' => (string) $request->getUri()->getPath()],
        ]);

        return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('admin')]);
    }
}
