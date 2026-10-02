<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class ProfileAction
{
    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private ActivityLogRepository $logs,
        private TransactionRepository $transactions,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
        private TopupRepository $topupRepo,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $errors = [];

        $row = $this->users->findById($identity->id);

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $action = (string) ($input['do'] ?? 'profile');

            if ($action === 'password') {
                $current = (string) ($input['current_password'] ?? '');
                $new = (string) ($input['new_password'] ?? '');
                $confirm = (string) ($input['confirm_password'] ?? '');

                if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
                    $errors['current_password'] = 'বর্তমান পাসওয়ার্ড সঠিক নয়।';
                } elseif (strlen($new) < 6) {
                    $errors['new_password'] = 'নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষর।';
                } elseif ($new !== $confirm) {
                    $errors['confirm_password'] = 'পাসওয়ার্ড মিলছে না।';
                } else {
                    $this->users->update($identity->id, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
                    $this->logs->create([
                        'user_id' => $identity->id,
                        'action' => 'profile.password_changed',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '-',
                    ]);
                    $this->session->set('flash_success', 'পাসওয়ার্ড পরিবর্তন হয়েছে।');
                }
            } else {
                $email = trim((string) ($input['email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'সঠিক ইমেইল দিন।';
                } else {
                    $this->users->update($identity->id, ['email' => $email ?: null]);
                    $this->session->set('flash_success', 'প্রোফাইল আপডেট হয়েছে।');
                }
            }

            if ($errors === []) {
                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('profile')]);
            }
        }

        $activity = $this->logs->forUser($identity->id, 1, 8);
        $stats = $this->transactions->statsForUser($identity->id);
        $topupData = $this->topupRepo->forUser($identity->id, 1, 5);

        return $this->view->render('site/account/profile.twig', [
            'user' => $row ?? [],
            'apiKey' => $row !== null ? $this->users->ensureApiKey($identity->id) : null,
            'activity' => $activity['rows'],
            'stats' => $stats,
            'errors' => $errors,
            'identity' => $identity,
            'topups' => $topupData['rows'],
        ]);
    }
}
