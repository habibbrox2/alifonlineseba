<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\UserRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminUsersApiAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private UserRepository $users,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $q = trim((string) ($params['q'] ?? ''));
        $sort = (string) ($params['sort'] ?? 'id');
        $dir = (string) ($params['dir'] ?? 'desc');
        $deleted = match ((string) ($params['trashed'] ?? '')) {
            UserRepository::DELETED_ONLY => UserRepository::DELETED_ONLY,
            UserRepository::DELETED_ALL => UserRepository::DELETED_ALL,
            default => UserRepository::DELETED_EXCLUDE,
        };

        $data = $this->users->paginate($page, self::PER_PAGE, $q, $sort, $dir, $deleted);

        return Api::ok([
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'trashedCount' => $this->users->countTrashed(),
        ]);
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $action = (string) ($input['do'] ?? '');

        if ($action === 'create') {
            return $this->createUser($input, $identity);
        }

        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            return Api::fail('ইউজার আইডি প্রয়োজন।');
        }

        $row = $this->users->findById($userId, $action === 'restore');
        if ($row === null) {
            return Api::fail('ইউজারটি পাওয়া যায়নি।', [], 404);
        }

        return match ($action) {
            'toggle' => $this->toggleUser($userId, $row),
            'role' => $this->changeRole($userId, $input),
            'reset' => $this->resetPassword($userId),
            'delete' => $this->deleteUser($userId, $row, $identity),
            'restore' => $this->restoreUser($userId, $row, $identity),
            'restore_all' => $this->restoreAllUsers($identity),
            default => Api::fail('অজানা অ্যাকশন।'),
        };
    }

    private function createUser(array $input, Identity $identity): ResponseInterface
    {
        $username = trim((string) ($input['username'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            $errors[] = 'ইউজারনেম ৩–৩২ অক্ষরের হতে হবে (a-z, 0-9, . _ -)।';
        }
        if ($this->users->usernameExists($username)) {
            $errors[] = 'এই ইউজারনেম আগে থেকেই আছে।';
        }
        if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
            $errors[] = 'সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন (যেমন 01712345678)।';
        }
        if ($this->users->phoneExists($phone)) {
            $errors[] = 'এই নম্বরে অ্যাকাউন্ট আছে।';
        }
        if (strlen($password) < 8) {
            $errors[] = 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।';
        }

        if ($errors !== []) {
            return Api::fail(implode(' ', $errors), $errors);
        }

        $role = in_array($input['role'] ?? '', ['user', 'staff', 'admin'], true) ? $input['role'] : 'user';
        $id = $this->users->create([
            'username' => $username,
            'phone' => $phone,
            'email' => trim((string) ($input['email'] ?? '')) ?: null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'balance' => max(0, (float) ($input['balance'] ?? 0)),
        ]);

        return Api::ok([
            'user_id' => $id,
            'message' => "ইউজার '{$username}' তৈরি হয়েছে।",
        ]);
    }

    private function toggleUser(int $userId, array $row): ResponseInterface
    {
        $this->users->update($userId, [
            'status' => $row['status'] === 'active' ? 'disabled' : 'active',
        ]);

        return Api::ok(['message' => 'ইউজার আপডেট হয়েছে।']);
    }

    private function changeRole(int $userId, array $input): ResponseInterface
    {
        $role = in_array($input['role'] ?? '', ['user', 'staff', 'admin'], true) ? $input['role'] : 'user';
        $this->users->update($userId, ['role' => $role]);

        return Api::ok(['message' => 'রোল আপডেট হয়েছে।']);
    }

    private function resetPassword(int $userId): ResponseInterface
    {
        $temporary = self::generatePassword();
        $this->users->update($userId, [
            'password_hash' => password_hash($temporary, PASSWORD_DEFAULT),
        ]);

        return Api::ok([
            'temporary_password' => $temporary,
            'message' => 'পাসওয়ার্ড রিসেট হয়েছে।',
        ]);
    }

    private function deleteUser(int $userId, array $row, Identity $identity): ResponseInterface
    {
        if ($userId === (int) $identity->id) {
            return Api::fail('নিজের অ্যাকাউন্ট মুছতে পারবেন না।');
        }
        if ($row['role'] === 'admin') {
            return Api::fail('অ্যাডমিন অ্যাকাউন্ট মুছতে পারবেন না — আগে রোল বদলান।');
        }
        if ($row['deleted_at'] !== null) {
            return Api::fail('ইউজারটি ইতিমধ্যে ট্র্যাশে আছে।');
        }
        if (!$this->users->softDelete($userId)) {
            return Api::fail('ইউজারটি মুছে ফেলা যায়নি।');
        }

        return Api::ok(['message' => "ইউজার '{$row['username']}' ট্র্যাশে পাঠানো হয়েছে।"]);
    }

    private function restoreUser(int $userId, array $row, Identity $identity): ResponseInterface
    {
        if ($row['deleted_at'] === null) {
            return Api::fail('ট্র্যাশে থাকা ইউজারটি পাওয়া যায়নি।');
        }
        if (!$this->users->restore($userId)) {
            return Api::fail('ইউজারটি রিস্টোর করা যায়নি।');
        }

        return Api::ok(['message' => "ইউজার '{$row['username']}' ফিরিয়ে আনা হয়েছে।"]);
    }

    private function restoreAllUsers(Identity $identity): ResponseInterface
    {
        $count = $this->users->restoreAll();

        if ($count === 0) {
            return Api::fail('ট্র্যাশে কোনো ইউজার নেই।');
        }

        return Api::ok(['message' => "{$count} জন ইউজার ফিরিয়ে আনা হয়েছে।"]);
    }

    /** Ambiguous glyphs (0/O, 1/l) are left out so the value can be read aloud. */
    private static function generatePassword(int $length = 14): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
