<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ProfileApiAction
{
    public function __construct(
        private UserRepository $users,
        private ActivityLogRepository $logs,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity|null $identity */
        $identity = $request->getAttribute('identity');
        if ($identity === null) {
            return Api::unauthorized();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
        }

        $fresh = $this->users->findById($identity->id);
        if ($fresh === null) {
            return Api::fail('ব্যবহারকারী পাওয়া যায়নি।', [], 404);
        }

        return Api::ok([
            'id' => (int) $fresh['id'],
            'username' => (string) $fresh['username'],
            'full_name' => (string) ($fresh['full_name'] ?? ''),
            'display_name' => ($fresh['full_name'] ?? '') !== '' ? (string) $fresh['full_name'] : (string) $fresh['username'],
            'phone' => (string) $fresh['phone'],
            'email' => $fresh['email'] !== null ? (string) $fresh['email'] : null,
            'role' => (string) $fresh['role'],
            'balance' => (float) ($fresh['balance'] ?? 0),
            'avatar' => $fresh['avatar'] ?? null,
        ]);
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $action = (string) ($input['action'] ?? $input['do'] ?? '');

        if ($action === 'password') {
            $current = (string) ($input['current_password'] ?? '');
            $new = (string) ($input['new_password'] ?? '');
            $confirm = (string) ($input['confirm_password'] ?? '');

            $row = $this->users->findById($identity->id);
            if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
                return Api::fail('বর্তমান পাসওয়ার্ড সঠিক নয়।', [], 422);
            }
            if (strlen($new) < 6) {
                return Api::fail('নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।', [], 422);
            }
            if ($new !== $confirm) {
                return Api::fail('পাসওয়ার্ড নিশ্চিতকরণ মেলেনি।', [], 422);
            }

            $this->users->update($identity->id, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
            $this->logs->create([
                'user_id' => $identity->id,
                'action' => 'profile.password_changed',
                'description' => 'Password updated via mobile/API',
                'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            ]);

            return Api::ok(null, 'পাসওয়ার্ড সফলভাবে পরিবর্তন করা হয়েছে।');
        }

        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));

        $update = [];
        if (isset($input['full_name'])) {
            $update['full_name'] = mb_substr($fullName, 0, 120);
        }
        if (isset($input['email'])) {
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Api::fail('সঠিক ইমেইল ঠিকানা দিন।', [], 422);
            }
            $update['email'] = $email ?: null;
        }
        if (isset($input['phone']) && $phone !== '') {
            $update['phone'] = $phone;
        }

        if ($update !== []) {
            $this->users->update($identity->id, $update);
            $this->logs->create([
                'user_id' => $identity->id,
                'action' => 'profile.updated',
                'description' => 'Profile info updated via mobile/API',
                'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            ]);
        }

        $fresh = $this->users->findById($identity->id);

        return Api::ok([
            'id' => (int) $fresh['id'],
            'username' => (string) $fresh['username'],
            'full_name' => (string) ($fresh['full_name'] ?? ''),
            'display_name' => ($fresh['full_name'] ?? '') !== '' ? (string) $fresh['full_name'] : (string) $fresh['username'],
            'phone' => (string) $fresh['phone'],
            'email' => $fresh['email'] !== null ? (string) $fresh['email'] : null,
            'role' => (string) $fresh['role'],
            'balance' => (float) ($fresh['balance'] ?? 0),
            'avatar' => $fresh['avatar'] ?? null,
        ], 'প্রোফাইল সফলভাবে আপডেট করা হয়েছে।');
    }
}
