<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\UserFields;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminUsersAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $identity = $request->getAttribute('identity');
        $adminId = $identity !== null ? (int) $identity->id : null;

        // POST actions: create, trash, restore, toggle status, change role, reset password
        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $action = (string) ($input['do'] ?? '');

            if ($action === 'create') {
                $this->createUser($input, $adminId);
            } elseif ($action === 'delete') {
                $this->trashUser((int) ($input['user_id'] ?? 0), $adminId);
            } elseif ($action === 'restore') {
                $this->restoreUser((int) ($input['user_id'] ?? 0), $adminId);
            } elseif ($action === 'restore_all') {
                $this->restoreAllUsers($adminId);
            } else {
                $userId = (int) ($input['user_id'] ?? 0);
                $row = $this->users->findById($userId);

                if ($row !== null) {
                    $temporaryPassword = null;

                    match ($action) {
                        'toggle' => $this->users->update($userId, [
                            'status' => $row['status'] === 'active' ? 'disabled' : 'active',
                        ]),
                        'role' => $this->users->update($userId, [
                            'role' => in_array($input['role'] ?? '', ['user', 'staff', 'admin'], true) ? $input['role'] : 'user',
                        ]),
                        'reset' => $temporaryPassword = $this->resetPassword($userId),
                        default => null,
                    };

                    $this->session->set(
                        'flash_success',
                        $temporaryPassword === null
                            ? 'ইউজার আপডেট হয়েছে।'
                            : "পাসওয়ার্ড রিসেট হয়েছে। নতুন পাসওয়ার্ড: {$temporaryPassword} — এটি একবারই দেখানো হবে, তাই এখনই কপি করে ব্যবহারকারীকে জানান।"
                    );
                }
            }

            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('admin-users')]);
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? '1'));
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $sort = (string) ($request->getQueryParams()['sort'] ?? 'id');
        $dir = (string) ($request->getQueryParams()['dir'] ?? 'desc');
        $deleted = self::deletedFilter((string) ($request->getQueryParams()['trashed'] ?? ''));
        $data = $this->users->paginate($page, self::PER_PAGE, $q, $sort, $dir, $deleted);

        return $this->view->render('site/admin/users.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'q' => $q,
            'sort' => in_array($sort, UserRepository::SORTABLE, true) ? $sort : 'id',
            'dir' => strtolower($dir) === 'asc' ? 'asc' : 'desc',
            'deletedFilter' => $deleted,
            'trashedCount' => $this->users->countTrashed(),
        ]);
    }

    /**
     * Replace a user's password with a fresh random value and return the
     * plaintext so it can be shown once. A fixed default would hand every
     * reset account the same guessable password, which is worse than no
     * reset at all on a public deployment.
     */
    private function resetPassword(int $userId): string
    {
        $temporary = self::generatePassword();
        $this->users->update($userId, [
            'password_hash' => password_hash($temporary, PASSWORD_DEFAULT),
        ]);

        return $temporary;
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

    /** Map the ?trashed= query value onto a UserRepository filter. */
    private static function deletedFilter(string $raw): string
    {
        return match ($raw) {
            UserRepository::DELETED_ONLY => UserRepository::DELETED_ONLY,
            UserRepository::DELETED_ALL => UserRepository::DELETED_ALL,
            default => UserRepository::DELETED_EXCLUDE,
        };
    }

    private function createUser(array $input, ?int $adminId): void
    {
        // The same validator the edit form uses, so an account cannot be
        // created under rules it would then be refused by.
        $checked = UserFields::validate($input);
        $values = $checked['values'];
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        foreach ($checked['errors'] as $message) {
            $errors[] = $message;
        }
        if ($this->users->usernameExists($values['username'])) {
            $errors[] = 'এই ইউজারনেম আগে থেকেই আছে।';
        }
        if ($this->users->phoneExists($values['phone'])) {
            $errors[] = 'এই নম্বরে অ্যাকাউন্ট আছে।';
        }
        if (strlen($password) < 8) {
            $errors[] = 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।';
        }

        if ($errors !== []) {
            $this->session->set('flash_error', implode(' ', $errors));
            return;
        }

        $role = in_array($input['role'] ?? '', ['user', 'staff', 'admin'], true) ? $input['role'] : 'user';
        $id = $this->users->create([
            'full_name' => $values['full_name'],
            'username' => $values['username'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            // Optional, and already `Y-m-d`: UserFields is the only thing that
            // decided what this column holds.
            'date_of_birth' => $values['date_of_birth'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'balance' => max(0, (float) ($input['balance'] ?? 0)),
        ]);

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.user.created',
            'description' => "User '{$values['username']}' ({$values['full_name']}) created (role: {$role})",
            'metadata' => ['new_user_id' => $id],
        ]);
        $this->session->set('flash_success', "ইউজার '{$values['full_name']}' ({$values['username']}) তৈরি হয়েছে।");
    }

    /**
     * Move a user to the trash. Nothing is lost: the row stays, so their
     * balance, transactions and notifications survive a restore, and the
     * account cannot log in while trashed.
     */
    private function trashUser(int $userId, ?int $adminId): void
    {
        $row = $userId > 0 ? $this->users->findById($userId, true) : null;

        if ($row === null) {
            return;
        }
        if ($userId === $adminId) {
            $this->session->set('flash_error', 'নিজের অ্যাকাউন্ট মুছতে পারবেন না।');
            return;
        }
        if ($row['role'] === 'admin') {
            $this->session->set('flash_error', 'অ্যাডমিন অ্যাকাউন্ট মুছতে পারবেন না — আগে রোল বদলান।');
            return;
        }
        if ($row['deleted_at'] !== null) {
            $this->session->set('flash_error', 'ইউজারটি ইতিমধ্যে ট্র্যাশে আছে।');
            return;
        }
        if (!$this->users->softDelete($userId)) {
            $this->session->set('flash_error', 'ইউজারটি মুছে ফেলা যায়নি।');
            return;
        }

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.user.trashed',
            'description' => "User '{$row['username']}' moved to trash",
            'metadata' => ['deleted_user_id' => $userId],
        ]);
        $this->session->set('flash_success', "ইউজার '{$row['username']}' ট্র্যাশে পাঠানো হয়েছে। চাইলে রিস্টোর করা যাবে।");
    }

    private function restoreUser(int $userId, ?int $adminId): void
    {
        $row = $userId > 0 ? $this->users->findById($userId, true) : null;

        if ($row === null || $row['deleted_at'] === null) {
            $this->session->set('flash_error', 'ট্র্যাশে থাকা ইউজারটি পাওয়া যায়নি।');
            return;
        }
        if (!$this->users->restore($userId)) {
            $this->session->set('flash_error', 'ইউজারটি রিস্টোর করা যায়নি।');
            return;
        }

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.user.restored',
            'description' => "User '{$row['username']}' restored from trash",
            'metadata' => ['deleted_user_id' => $userId],
        ]);
        $this->session->set('flash_success', "ইউজার '{$row['username']}' ফিরিয়ে আনা হয়েছে।");
    }

    private function restoreAllUsers(?int $adminId): void
    {
        $count = $this->users->restoreAll();

        if ($count === 0) {
            $this->session->set('flash_error', 'ট্র্যাশে কোনো ইউজার নেই।');
            return;
        }

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.user.restored',
            'description' => "All {$count} trashed user(s) restored",
            'metadata' => ['count' => $count],
        ]);
        $this->session->set('flash_success', "{$count} জন ইউজার ফিরিয়ে আনা হয়েছে।");
    }
}
