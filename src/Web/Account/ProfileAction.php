<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Notification\Push\VapidKeys;
use App\Repository\ActivityLogRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TopupRepository;
use App\Repository\ServiceOrderRepository;
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
        private ServiceOrderRepository $orders,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
        private TopupRepository $topupRepo,
        private PushSubscriptionRepository $subscriptions,
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
            } elseif ($action === 'push') {
                $this->togglePush($identity, (string) ($input['enabled'] ?? '0'));
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
        $stats = $this->orders->statsForUser($identity->id);
        $topupData = $this->topupRepo->forUser($identity->id, 1, 5);
        $vapid = VapidKeys::fromEnv();

        return $this->view->render('site/account/profile.twig', [
            'user' => $row ?? [],
            'apiKey' => $row !== null ? $this->users->ensureApiKey($identity->id) : null,
            'activity' => $activity['rows'],
            'stats' => $stats,
            'errors' => $errors,
            'identity' => $identity,
            'topups' => $topupData['rows'],
            // — Push settings —
            // `isPushEnabled()` rather than the column: a database the migration
            // has not reached yet must still render this page, and the whole
            // point of the tolerant read is that an absent column reads as "on".
            'pushEnabled' => $this->users->isPushEnabled($identity->id),
            'pushDevices' => $this->subscriptions->forUser($identity->id),
            'pushConfigured' => $vapid !== null,
            'vapidPublicKey' => $vapid?->publicKey(),
        ]);
    }

    /**
     * The account-wide push switch.
     *
     * ## Why this is a POST and not a checkbox the fan-out polls
     *
     * The flag is checked twice — once here, once inside `WebPushChannel::send()`
     * — because a queued notification can sit in the table for a while after the
     * switch is flipped. The second check is the one that matters; this handler
     * exists to make the state durable and to tell the user what happened.
     *
     * Turning it off also deactivates the subscriptions, which is the part a
     * flag alone cannot do. A browser that still holds a live `PushSubscription`
     * keeps receiving anything broadcast to "every active device", and the
     * person who just said "not now" would keep getting it until they cleared
     * site data by hand.
     */
    private function togglePush(Identity $identity, string $enabled): void
    {
        $on = $enabled === '1';

        $this->users->setPushEnabled($identity->id, $on);

        $dropped = $on ? 0 : $this->subscriptions->deactivateAllForUser($identity->id);

        $this->logs->create([
            'user_id' => $identity->id,
            'action' => $on ? 'profile.push_enabled' : 'profile.push_disabled',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '-',
        ]);

        $this->session->set('flash_success', $on
            ? 'পুশ নোটিফিকেশন চালু হয়েছে — এখন ব্রাউজারে অনুমতি দিন।'
            : ($dropped > 0
                ? "পুশ নোটিফিকেশন বন্ধ হয়েছে — {$dropped}টি ব্রাউজার আর পাবে না।"
                : 'পুশ নোটিফিকেশন বন্ধ হয়েছে।'));
    }
}
