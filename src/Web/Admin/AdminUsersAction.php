<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\PermissionService;
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
        private PermissionService $permissions,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $identity = $request->getAttribute('identity');
        $adminId = $identity !== null ? (int) $identity->id : null;

        // POST actions: create, trash, restore, toggle status, change role,
        // reset password, and the bulk status bar.
        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $action = (string) ($input['do'] ?? '');

            // The template only renders a control the actor may use, but a POST
            // body is attacker-controlled: every action re-checks its granular
            // permission here, so a hand-made request cannot skip the gate.
            $permission = [
                'create' => 'admin-users-create',
                'delete' => 'admin-users-delete',
                'restore' => 'admin-users-restore',
                'restore_all' => 'admin-users-restore',
                'toggle' => 'admin-users-status-update',
                'role' => 'admin-users-role-update',
                'reset' => 'admin-users-password-reset',
                'bulk_status' => 'admin-users-status-update',
                'bulk_role' => 'admin-users-role-update',
                'bulk_trash' => 'admin-users-delete',
                'bulk_restore' => 'admin-users-restore',
            ][$action] ?? null;

            if ($permission !== null && (!$identity instanceof Identity
                || !$this->permissions->canAccessRoute($identity, $permission))) {
                $this->session->set('flash_error', 'আপনার এই অ্যাকশন করার অনুমতি নেই।');
            } elseif (str_starts_with($action, 'bulk_')) {
                $this->bulkApply($action, $input, $identity, $adminId);
            } elseif ($action === 'create') {
                $this->createUser($input, $adminId);
            } elseif ($action === 'delete') {
                $this->trashUser(
                    (int) ($input['user_id'] ?? 0),
                    $identity instanceof Identity ? $identity : null,
                    $adminId,
                );
            } elseif ($action === 'restore') {
                $this->restoreUser((int) ($input['user_id'] ?? 0), $adminId);
            } elseif ($action === 'restore_all') {
                $this->restoreAllUsers($adminId);
            } else {
                $userId = (int) ($input['user_id'] ?? 0);
                $row = $userId > 0 ? $this->users->findById($userId) : null;

                if ($row !== null) {
                    $temporaryPassword = null;
                    $updated = true;

                    match ($action) {
                        'toggle' => $updated = $this->toggleStatus($identity, $userId, $row, $adminId),
                        'role' => $updated = $this->changeRole($identity, $userId, $row, (string) ($input['role'] ?? '')),
                        'reset' => $temporaryPassword = $this->resetPassword($userId),
                        default => null,
                    };

                    if (!$updated) {
                        // The guard already set the refusal flash.
                    } elseif ($temporaryPassword === null) {
                        $this->session->set('flash_success', 'ইউজার আপডেট হয়েছে।');
                    } else {
                        $this->session->set(
                            'flash_success',
                            "পাসওয়ার্ড রিসেট হয়েছে। নতুন পাসওয়ার্ড: {$temporaryPassword} — এটি একবারই দেখানো হবে, তাই এখনই কপি করে ব্যবহারকারীকে জানান।"
                        );
                    }
                }
            }

            // A bulk run must not dump the operator back on page 1 with the
            // search gone: the batch was picked *within* a filtered view.
            $location = $this->url->generate('admin-users')
                . (str_starts_with($action, 'bulk_') ? $this->bulkReturnQuery($input) : '');

            return new \Nyholm\Psr7\Response(302, ['Location' => $location]);
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
     * The roles this actor may assign: the three ordinary ones for everyone,
     * plus superadmin only for a superadmin. Shared by the single-row edit and
     * the bulk bar so both refuse the same set.
     *
     * @return list<string>
     */
    private function allowedRoles(object $identity): array
    {
        return $identity instanceof Identity && $identity->isSuperAdmin()
            ? ['user', 'staff', 'admin', 'superadmin']
            : ['user', 'staff', 'admin'];
    }

    /**
     * Flip one account's status. The bulk bar repeats these guards per row,
     * so both paths must agree: never yourself, never a rank you cannot
     * manage, never the last active superadmin.
     */
    private function toggleStatus(object $identity, int $userId, array $row, ?int $adminId): bool
    {
        $target = ($row['status'] ?? '') === 'active' ? 'disabled' : 'active';

        $refused = $userId === (int) $adminId
            || !($identity instanceof Identity && $identity->canManageRole((string) $row['role']))
            || ($row['role'] === 'superadmin' && $target !== 'active' && !$this->users->hasOtherSuperAdmin($userId));

        if ($refused) {
            $this->session->set('flash_error', 'এই অ্যাকাউন্টের স্ট্যাটাস পরিবর্তন করা যাবে না।');
            return false;
        }

        $this->users->update($userId, ['status' => $target]);
        return true;
    }

    /**
     * Apply a role to one account. The target must be one this actor may
     * assign — rejected outright rather than clamped, because a typo must be
     * an error, never a silent demotion to 'user' — and then the rank guard
     * applies, so an admin cannot demote a row above their own rank.
     */
    private function changeRole(object $identity, int $userId, array $row, string $requestedRole): bool
    {
        if (!($identity instanceof Identity) || !$identity->canManageRole((string) $row['role'])) {
            $this->session->set('flash_error', 'এই অ্যাকাউন্টের রোল পরিবর্তন করা যাবে না।');
            return false;
        }

        if (!in_array($requestedRole, $this->allowedRoles($identity), true)) {
            $this->session->set('flash_error', 'ভূমিকা বেছে নিন।');
            return false;
        }

        $this->users->update($userId, ['role' => $requestedRole]);
        return true;
    }

    /**
     * Run one bulk bar action — status, role, trash or restore — over the
     * selected batch.
     *
     * The checkbox column only offers rows this actor may touch, but the POST
     * body is attacker-controlled: every id is re-checked here against the
     * same guards as the single-row paths — self, rank, trash state, the
     * last-superadmin rule — and counted rather than trusted.
     */
    private function bulkApply(string $do, array $input, ?Identity $identity, ?int $adminId): void
    {
        if (!$identity instanceof Identity) {
            $this->session->set('flash_error', 'অনুমতি নেই।');
            return;
        }

        if (!in_array($do, ['bulk_status', 'bulk_role', 'bulk_trash', 'bulk_restore'], true)) {
            $this->session->set('flash_error', 'অজানা অ্যাকশন।');
            return;
        }

        $targetStatus = ((string) ($input['status'] ?? '')) === 'active' ? 'active' : 'disabled';
        $targetRole = (string) ($input['role'] ?? '');
        if ($do === 'bulk_role' && !in_array($targetRole, $this->allowedRoles($identity), true)) {
            // Rejected rather than clamped, exactly as the single-row path is:
            // a hand-made POST with a bogus role must never become a silent
            // demotion of the batch to 'user'.
            $this->session->set('flash_error', 'ভূমিকা বেছে নিন।');
            return;
        }

        $ids = [];
        foreach ((array) ($input['ids'] ?? []) as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        if ($ids === []) {
            $this->session->set('flash_error', 'কোনো ইউজার নির্বাচন করা হয়নি।');
            return;
        }

        $changed = 0;
        $skipped = 0;
        $touched = [];

        foreach ($ids as $id) {
            // withDeleted: the restore leg can only find its rows this way, and
            // trash state is decided per-action below, not by the lookup.
            $row = $id === (int) $identity->id ? null : $this->users->findById($id, true);
            $rowRole = $row === null ? '' : (string) $row['role'];
            $trashed = $row !== null && $row['deleted_at'] !== null;

            $refused = $row === null
                || !$identity->canManageRole($rowRole)
                || ($do === 'bulk_restore' ? !$trashed : $trashed);

            if (!$refused) {
                $refused = match ($do) {
                    'bulk_status' => (string) $row['status'] === $targetStatus
                        || ($rowRole === 'superadmin' && $targetStatus !== 'active' && !$this->users->hasOtherSuperAdmin($id)),
                    'bulk_role' => $rowRole === $targetRole
                        || ($rowRole === 'superadmin' && $targetRole !== 'superadmin' && !$this->users->hasOtherSuperAdmin($id)),
                    'bulk_trash' => in_array($rowRole, ['admin', 'superadmin'], true),
                    default => false,
                };
            }

            if ($refused) {
                $skipped++;
                continue;
            }

            if ($do === 'bulk_status') {
                $this->users->update($id, ['status' => $targetStatus]);
                $ok = true;
            } elseif ($do === 'bulk_role') {
                $this->users->update($id, ['role' => $targetRole]);
                $ok = true;
            } else {
                $ok = match ($do) {
                    'bulk_trash' => $this->users->softDelete($id),
                    default => $this->users->restore($id),
                };
            }

            if ($ok) {
                $changed++;
                $touched[] = $id;
            } else {
                $skipped++;
            }
        }

        if ($changed > 0) {
            $this->logs->create([
                'user_id' => $adminId,
                'action' => "admin.user.{$do}",
                'description' => match ($do) {
                    'bulk_status' => "Bulk status change to '{$targetStatus}': {$changed} user(s)",
                    'bulk_role' => "Bulk role change to '{$targetRole}': {$changed} user(s)",
                    'bulk_trash' => "Moved to trash: {$changed} user(s)",
                    default => "Restored from trash: {$changed} user(s)",
                },
                'metadata' => [
                    'user_ids' => $touched,
                    'target' => $do === 'bulk_role' ? $targetRole : $targetStatus,
                ],
            ]);
        }

        $message = match ($do) {
            'bulk_status' => sprintf(
                'নির্বাচিত ইউজারদের মধ্যে %d জনের স্ট্যাটাস %s হয়েছে।',
                $changed,
                $targetStatus === 'active' ? 'সক্রিয়' : 'নিষ্ক্রিয়',
            ),
            'bulk_role' => sprintf('নির্বাচিত ইউজারদের মধ্যে %d জনের রোল %s হয়েছে।', $changed, $targetRole),
            'bulk_trash' => sprintf('নির্বাচিত ইউজারদের মধ্যে %d জন ট্র্যাশে পাঠানো হয়েছে।', $changed),
            default => sprintf('নির্বাচিত ইউজারদের মধ্যে %d জন ফিরিয়ে আনা হয়েছে।', $changed),
        };
        if ($skipped > 0) {
            $message .= " {$skipped} জন বাদ পড়েছে — নিজের অ্যাকাউন্ট, উচ্চতর ভূমিকা, অনুপযুক্ত অবস্থা বা ইতিমধ্যে একই ফলাফল।";
        }

        $this->session->set($changed > 0 ? 'flash_success' : 'flash_error', $message);
    }

    /**
     * The filter state a bulk run was picked within, as a query string, so
     * the redirect lands back on the same page of the same search.
     */
    private function bulkReturnQuery(array $input): string
    {
        $query = [];

        $q = trim((string) ($input['return_q'] ?? ''));
        if ($q !== '') {
            $query['q'] = $q;
        }

        $trashed = self::deletedFilter((string) ($input['return_trashed'] ?? ''));
        if ($trashed !== UserRepository::DELETED_EXCLUDE) {
            $query['trashed'] = $trashed;
        }

        $page = (int) ($input['return_page'] ?? 0);
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        $sort = (string) ($input['return_sort'] ?? '');
        if (in_array($sort, UserRepository::SORTABLE, true)) {
            $query['sort'] = $sort;
        }

        $dir = strtolower((string) ($input['return_dir'] ?? ''));
        if (in_array($dir, ['asc', 'desc'], true)) {
            $query['dir'] = $dir;
        }

        return $query === [] ? '' : '?' . http_build_query($query);
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
    private function trashUser(int $userId, ?Identity $identity, ?int $adminId): void
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
        // The rank gate belongs on the server, not only in the template: a
        // hand-made POST carrying a superadmin's id must not let a lower rank
        // trash the platform's last authority.
        if (!$identity instanceof Identity || !$identity->canManageRole((string) $row['role'])) {
            $this->session->set('flash_error', 'এই অ্যাকাউন্ট ট্র্যাশে পাঠানোর ক্ষমতা আপনার নেই।');
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
