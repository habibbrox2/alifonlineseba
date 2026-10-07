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
use App\Service\ImageUploadStorage;
use App\Service\ServiceDate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
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
        private ImageUploadStorage $imageStorage,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $errors = [];

        $row = $this->users->findById($identity->id);

        // Track uploads that were accepted during this request so we can roll
        // back any that were stored before a later field rejected, instead of
        // leaving orphans in the hashed bucket.
        /** @var array<string> $acceptedPaths */
        $acceptedPaths = [];

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
            } elseif ($action === 'avatar') {
                $this->handleAvatar($identity, $request->getUploadedFiles(), $acceptedPaths, $errors);
            } else {
                $fullName = trim((string) ($input['full_name'] ?? ''));
                $email = trim((string) ($input['email'] ?? ''));
                $whatsapp = self::normalizePhone((string) ($input['whatsapp_no'] ?? ''));
                $telegram = trim((string) ($input['telegram_no'] ?? ''));
                // Typed as `06-10-2026`, stored as `2026-10-06`, judged by the
                // same class every service form is judged by — an optional
                // answer, so a blank is legal and clears the column.
                $birthTyped = trim((string) ($input['date_of_birth'] ?? ''));
                $birthDate = ServiceDate::canonical($birthTyped);

                // Same contract as signup: the name is required, and the 120
                // cap mirrors the column so MySQL never truncates what the
                // person just typed.
                if ($fullName === '' || mb_strlen($fullName) < 2) {
                    $errors['full_name'] = 'পুরো নাম লিখুন (কমপক্ষে ২ অক্ষর)।';
                } elseif (mb_strlen($fullName) > 120) {
                    $errors['full_name'] = 'পুরো নাম ১২০ অক্ষরের বেশি হতে পারবে না।';
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'সঠিক ইমেইল দিন।';
                }
                if (($input['whatsapp_no'] ?? '') !== '' && !self::isPhoneLike($whatsapp)) {
                    $errors['whatsapp_no'] = 'সঠিক হোয়াটসঅ্যাপ নম্বর দিন (যেমন 01712345678)।';
                }
                if ($telegram !== '' && !self::isTelegramHandle($telegram)) {
                    $errors['telegram_no'] = 'সঠিক টেলিগ্রাম ইউজারনেম বা নম্বর দিন।';
                }
                if ($birthTyped !== '' && $birthDate === null) {
                    $errors['date_of_birth'] = ServiceDate::errorMessage();
                }
                // Two people can legitimately share a WhatsApp line, so this is
                // a *duplicate* check rather than a unique constraint: it stops
                // a copy-paste mistake from silently rerouting somebody else's
                // notifications, without refusing a household that has one phone.
                if ($errors === [] && $whatsapp !== '' && $whatsapp !== (string) ($row['phone'] ?? '')) {
                    $owner = $this->users->findByContact('whatsapp', $whatsapp);
                    if ($owner !== null && $owner !== (int) $identity->id) {
                        $errors['whatsapp_no'] = 'এই নম্বরটি অন্য একটি অ্যাকাউন্টে যুক্ত আছে।';
                    }
                }
                if ($errors === [] && $telegram !== '' && $telegram !== (string) ($row['phone'] ?? '')) {
                    $owner = $this->users->findByContact('telegram', $telegram);
                    if ($owner !== null && $owner !== (int) $identity->id) {
                        $errors['telegram_no'] = 'এই টেলিগ্রাম আইডি অন্য একটি অ্যাকাউন্টে যুক্ত আছে।';
                    }
                }

                if ($errors === []) {
                    $this->users->update($identity->id, [
                        'full_name' => $fullName,
                        'email' => $email ?: null,
                        'whatsapp_no' => $whatsapp ?: null,
                        'telegram_no' => $telegram ?: null,
                        'date_of_birth' => $birthDate,
                    ]);
                    $this->session->set('flash_success', 'প্রোফাইল আপডেট হয়েছে।');
                }
            }

            // Roll back any avatar upload that was accepted before a later
            // validation error — the hashed bucket is only reachable through the
            // owning row, so an orphan there is a silent leak.
            if ($errors !== []) {
                foreach ($acceptedPaths as $path) {
                    $this->imageStorage->delete($path);
                }
                $acceptedPaths = [];
            }

            if ($errors === []) {
                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('profile')]);
            }

            // Redisplay what was typed rather than the stored row: a validation
            // error that silently reverts the form to the old values is how a
            // person ends up retyping the same field three times.
            $row = array_merge($row ?? [], [
                'full_name' => $fullName ?? ($row['full_name'] ?? null),
                'email' => $email ?? ($row['email'] ?? null),
                'whatsapp_no' => $whatsapp ?? ($row['whatsapp_no'] ?? null),
                'telegram_no' => $telegram ?? ($row['telegram_no'] ?? null),
                // Back in the form the way it was typed, not as the ISO the
                // row holds — otherwise a rejected date would come back
                // reformatted and the person would not recognise their answer.
                'date_of_birth' => $birthTyped ?? ($row['date_of_birth'] ?? null),
            ]);
        }

        $activity = $this->logs->forUser($identity->id, 1, 8);
        $stats = $this->orders->statsForUser($identity->id);
        $topupData = $this->topupRepo->forUser($identity->id, 1, 5);
        $vapid = VapidKeys::fromEnv();

        return $this->view->render('site/account/profile.twig', [
            'user' => $row ?? [],
            // What the date field shows. On a fresh GET that is the stored
            // `2026-10-06`; after a rejected POST it is the typed text the
            // redisplay above put back into the row.
            'dateOfBirthDisplay' => ServiceDate::display($row['date_of_birth'] ?? ''),
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
            // — Avatar —
            // The storage path on the row, or null when the user has no avatar.
            // Used by the template to render the current avatar (or the fallback
            // initials chip) and to decide whether the delete button should show.
            'avatarPath' => $row['avatar'] ?? null,
            'avatarUrl' => $row['avatar'] !== null
                ? $this->url->generate('profile-avatar', ['id' => (string) $identity->id])
                : null,
        ]);
    }

    /**
     * Handle the avatar upload POST.
     *
     * One file field (`avatar`), optional — a POST with no file attached is a
     * "remove my current avatar" request rather than an error. The existing
     * storage entry is deleted when no new file is supplied, so the user can
     * clear their avatar without leaving a stale path on the row.
     *
     * @param array<string, \Psr\Http\Message\UploadedFileInterface|null> $files
     * @param array<string> $acceptedPaths  storage paths accepted in this request,
     *                                      rolled back on error
     * @param array<string, string> $errors  collected validation errors; the
     *                                        caller refuses the redirect when non-empty
     */
    private function handleAvatar(
        Identity $identity,
        array $files,
        array &$acceptedPaths,
        array &$errors,
    ): void {
        $upload = $files['avatar'] ?? null;

        // No file at all — the user wants to remove their current avatar.
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            $row = $this->users->findById($identity->id);
            if ($row !== null && ($row['avatar'] ?? null) !== null) {
                $this->imageStorage->delete($row['avatar']);
                $this->users->update($identity->id, ['avatar' => null]);
                $this->logs->create([
                    'user_id' => $identity->id,
                    'action' => 'profile.avatar_removed',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '-',
                ]);
                $this->session->set('flash_success', 'প্রোফাইল ছবি অপসারণ করা হয়েছে।');
            }
            return;
        }

        // A file was supplied — validate and store it.
        try {
            $result = $this->imageStorage->store($upload, 'avatars', 200 * 1024);
        } catch (\RuntimeException $e) {
            $errors['avatar'] = $e->getMessage();
            return;
        }

        // Replace the existing avatar — the old bytes must be removed so the
        // hashed bucket does not keep growing. If the storage delete fails the
        // new file still stands; the orphan is harmless (the bucket keeps it,
        // and the next upload by anyone reuses the same hashed directory).
        $row = $this->users->findById($identity->id);
        if ($row !== null && ($row['avatar'] ?? null) !== null) {
            $this->imageStorage->delete($row['avatar']);
        }

        $this->users->update($identity->id, ['avatar' => $result['path']]);
        $acceptedPaths[] = $result['path'];

        $this->logs->create([
            'user_id' => $identity->id,
            'action' => 'profile.avatar_changed',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '-',
        ]);

        $this->session->set('flash_success', 'প্রোফাইল ছবি আপডেট করা হয়েছে।');
    }

    /**
     * Digits with an optional country prefix, as the column stores them.
     *
     * Spaces, dashes and parentheses are stripped rather than rejected: the
     * number arrives from a human copying it out of their phone's contact card
     * as often as from a form, and "01712-345 678" is the same number.
     */
    private static function normalizePhone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $digits = preg_replace('/[^0-9+]/', '', $value) ?? '';
        if (str_starts_with($digits, '+880')) {
            $digits = '0' . substr($digits, 4);
        }
        return $digits;
    }

    /** 8–15 digits after normalization — the international E.164 envelope. */
    private static function isPhoneLike(string $value): bool
    {
        $digits = ltrim($value, '+');
        return preg_match('/^[0-9]{8,15}$/', $digits) === 1;
    }

    /**
     * An @handle or a phone number, because Telegram is addressed either way
     * depending on whether the person has ever opened the bot.
     */
    private static function isTelegramHandle(string $value): bool
    {
        if (preg_match('/^@?[a-zA-Z][a-zA-Z0-9_]{3,31}$/', $value) === 1) {
            return true;
        }
        return preg_match('/^\+?[0-9]{8,15}$/', $value) === 1;
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
