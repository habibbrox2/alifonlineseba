<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\UserRepository;
use App\Service\Api;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminStaffApiAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private UserRepository $users,
        private PermissionService $permissions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        // Check granular permission for staff read
        if (!$this->permissions->canAccessRoute($identity, 'admin-staff')) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $q = trim((string) ($params['q'] ?? ''));

        $data = $this->users->paginateStaff($page, self::PER_PAGE, $q);

        return Api::ok([
            // SELECT * with a credential column in it — never ship the hash.
            'rows' => array_map(static fn (array $row): array => array_diff_key(
                $row,
                array_flip(['password_hash', 'remember_token']),
            ), $data['rows']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'q' => $q,
        ]);
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $action = (string) ($input['do'] ?? '');

        if ($action === 'reset') {
            if (!$this->permissions->canAccessRoute($identity, 'admin-staff-password-reset')) {
                return Api::fail('আপনার এই অ্যাকশন করার অনুমতি নেই।');
            }
            return $this->resetPassword($input, $identity);
        }

        return Api::fail('অজানা অ্যাকশন।');
    }

    private function resetPassword(array $input, Identity $identity): ResponseInterface
    {
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            return Api::fail('স্টাফ ইউজার আইডি প্রয়োজন।');
        }

        $row = $this->users->findById($userId);
        if ($row === null) {
            return Api::fail('ইউজারটি পাওয়া যায়নি।', [], 404);
        }

        // Only staff/admin/superadmin accounts are managed here
        if (!in_array($row['role'], ['admin', 'staff', 'superadmin'], true)) {
            return Api::fail('এই অ্যাকাউন্টটি স্টাফ নয়।');
        }

        // Never reset your own password through this action — use the profile form
        if ($userId === (int) $identity->id) {
            return Api::fail('নিজের পাসওয়ার্ড এখানে রিসেট করতে পারবেন না — প্রোফাইল থেকে করুন।');
        }

        // Guard against resetting the last superadmin's password to nothing
        if ($row['role'] === 'superadmin' && !$this->users->hasOtherSuperAdmin($userId)) {
            return Api::fail('শেষ সুপারএডমিনের পাসওয়ার্ড রিসেট করা যাবে না।');
        }

        $temporary = self::generatePassword();
        $this->users->update($userId, [
            'password_hash' => password_hash($temporary, PASSWORD_DEFAULT),
        ]);

        return Api::ok([
            'temporary_password' => $temporary,
            'message' => 'পাসওয়ার্ড রিসেট হয়েছে।',
        ]);
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
