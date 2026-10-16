<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/staff — the staff roster of operators.
 *
 * A platform with one operator does not need this page; a platform with several
 * does, and the moment it needs it is the moment somebody asks "who can approve
 * an order and who can pay it out?". The roster is the answer, and it is
 * deliberately a page rather than a settings dropdown, because an authority
 * that is changed quietly is an authority nobody knows exists.
 *
 * Access control:
 * - Superadmins have full access (can view and modify all staff including superadmins)
 * - Regular admins can view the roster but CANNOT modify superadmin accounts
 *   (cannot change role, status, or reset password of superadmins)
 *
 * Two rules are enforced here that are easy to forget and expensive to get
 * wrong:
 *
 * - **The last super-admin cannot be demoted, suspended or trashed.** The panel
 *   would keep working and simply stop being able to pay anybody out. Refusing
 *   while one other remains is the whole guard — see
 *   {@see UserRepository::hasOtherSuperAdmin()}.
 * - **Nobody edits their own row.** Otherwise the super-admin who demotes a
 *   colleague can be demoted by that colleague from the same screen, and the
 *   roster becomes a two-click way to hand the platform to anybody who guesses
 *   the URL.
 *
 * Everything that changes a role writes an activity-log row naming the actor
 * and the subject, so "who made this admin a super-admin" has an answer that
 * does not depend on anybody remembering.
 */
final readonly class AdminStaffAction
{
    /** Roles this screen may assign. `user` is here so an operator can be stood down. */
    private const ASSIGNABLE = ['superadmin', 'admin', 'staff', 'user'];
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private TransactionRepository $ledger,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
        private PermissionService $permissions,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $do = (string) ($input['do'] ?? '');

            // The bulk bar posts many ids; the single-row path posts one. Both
            // answer with the same [ok, message] contract, so the redirect and
            // the flash below stay identical.
            [$ok, $message] = str_starts_with($do, 'bulk_')
                ? $this->bulkApply($do, $input, $identity)
                : $this->apply($do, $input, $identity);
            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

            // A bulk run must not dump the operator back on page 1 with the
            // search gone: the batch was picked *within* a filtered view.
            $location = $this->url->generate('admin-staff')
                . (str_starts_with($do, 'bulk_') ? $this->bulkReturnQuery($input) : '');

            return new \Nyholm\Psr7\Response(302, ['Location' => $location]);
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $q = trim((string) ($query['q'] ?? ''));
        $sort = (string) ($query['sort'] ?? 'role');
        $dir = (string) ($query['dir'] ?? 'asc');
        $trashed = match ((string) ($query['trashed'] ?? '')) {
            UserRepository::DELETED_ONLY => UserRepository::DELETED_ONLY,
            UserRepository::DELETED_ALL => UserRepository::DELETED_ALL,
            default => UserRepository::DELETED_EXCLUDE,
        };

        $data = $this->users->paginateStaff($page, self::PER_PAGE, $q, $sort, $dir, $trashed);

        $trashedQuery = $trashed !== UserRepository::DELETED_EXCLUDE ? ('trashed=' . $trashed . '&') : '';
        $baseUrl = '/admin/staff?' . ($q ? ('q=' . urlencode($q) . '&') : '') . $trashedQuery;

        return $this->view->render('site/admin/staff.twig', [
            'staff' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'q' => $q,
            'sort' => $sort,
            'dir' => $dir,
            'trashedFilter' => $trashed,
            'trashedCount' => $this->users->countTrashed(),
            'earnings' => $this->earnings(),
            'roleLabels' => UserRepository::ROLE_LABELS,
            'identity' => $identity,
            'baseUrl' => $baseUrl,
        ]);
    }

    /**
     * Check if the current user can modify the target staff member.
     * Uses RBAC role ranking: superadmin > admin > staff > user
     */
    private function canModify(Identity $identity, array $subject): bool
    {
        // Cannot modify self
        if ((int) $subject['id'] === $identity->id) {
            return false;
        }
        // Use RBAC role ranking
        return $identity->canManageRole((string) $subject['role']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: bool, 1: string}
     */
    private function apply(string $do, array $input, Identity $identity): array
    {
        $id = (int) ($input['id'] ?? 0);
        $subject = $id > 0 ? $this->users->findById($id, true) : null;

        if ($subject === null) {
            return [false, 'স্টাফ অ্যাকাউন্ট পাওয়া যায়নি।'];
        }

        // Self-edit is refused for every action on this page, not just the
        // dangerous ones: the roster is a statement about who is in charge, and
        // letting its subjects annotate it defeats that.
        if ($id === $identity->id) {
            return [false, 'নিজের অ্যাকাউন্টের ভূমিকা পরিবর্তন করা যায় না।'];
        }

        // Regular admins cannot modify superadmin accounts
        if (!$this->canModify($identity, $subject)) {
            return [false, 'সুপারএডমিন অ্যাকাউন্ট পরিবর্তন করার অনুমতি নেই।'];}

        // Check granular permissions
        return match ($do) {
            'role' => $this->checkPermission($identity, 'admin-staff-role-update')
                ? $this->setRole($input, $identity, $subject)
                : [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'],
            'status' => $this->checkPermission($identity, 'admin-staff-status-update')
                ? $this->setStatus($input, $identity, $subject)
                : [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'],
            'password' => $this->checkPermission($identity, 'admin-staff-password-reset')
                ? $this->resetPassword($input, $subject)
                : [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'],
            'restore' => $this->checkPermission($identity, 'admin-users-restore')
                ? $this->restoreStaff($subject, $identity)
                : [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'],
            'restore_all' => $this->checkPermission($identity, 'admin-users-restore')
                ? $this->restoreAllStaff($identity)
                : [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'],
            default => [false, 'অজানা অ্যাকশন।'],
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $subject
     * @return array{0: bool, 1: string}
     */
    private function setRole(array $input, Identity $identity, array $subject): array
    {
        $role = (string) ($input['role'] ?? '');
        if (!in_array($role, self::ASSIGNABLE, true)) {
            return [false, 'ভূমিকা বেছে নিন।'];
        }

        // The single-row dropdown sits inside staff.update, which admins also
        // hold — only manage_superadmin (superadmin-only) may mint another
        // superadmin, or an admin could quietly hand themselves a peer.
        if ($role === Identity::ROLE_SUPERADMIN
            && !$this->checkPermission($identity, 'admin-staff-manage-superadmin')) {
            return [false, 'সুপারএডমিন ভূমিকা দেওয়ার অনুমতি নেই।'];
        }

        $from = (string) $subject['role'];
        if ($from === $role) {
            return [true, 'ভূমিকা অপরিবর্তিত আছে।'];
        }

        if (!$this->guardedAgainstLastSuperAdmin($subject, $from, $role, (string) $subject['status'])) {
            return [false, 'শেষ সুপারএডমিনের ভূমিকা পরিবর্তন করা যাবে না। আগে অন্য কাউকে সুপারএডমিন করুন।'];
        }

        $this->users->setRole((int) $subject['id'], $role, (string) $subject['status']);
        $this->log(
            $identity,
            (int) $subject['id'],
            'admin.role_changed',
            sprintf('Role %s -> %s for %s', $from, $role, (string) $subject['username']),
        );

        return [true, sprintf(
            '%s-এর ভূমিকা পরিবর্তন হয়েছে: %s',
            (string) $subject['username'],
            UserRepository::ROLE_LABELS[$role] ?? $role,
        )];
    }

    /**
     * Run one bulk bar action — status, role, trash or restore — over the
     * selected roster rows.
     *
     * The checkbox column only offers rows this actor may touch, but the POST
     * body is attacker-controlled: each id is re-checked here with the same
     * guards as the single-row paths — roster scope, self, rank, trash state,
     * no-op result and the last-superadmin rule — and counted rather than
     * trusted.
     *
     * @param array<string, mixed> $input
     * @return array{0: bool, 1: string}
     */
    private function bulkApply(string $do, array $input, Identity $identity): array
    {
        $permission = [
            'bulk_status' => 'admin-staff-status-update',
            'bulk_role' => 'admin-staff-role-update',
            'bulk_trash' => 'admin-staff-delete',
            'bulk_restore' => 'admin-users-restore',
        ][$do] ?? null;

        if ($permission === null) {
            return [false, 'অজানা অ্যাকশন।'];
        }

        if (!$this->checkPermission($identity, $permission)) {
            return [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'];
        }

        $status = ((string) ($input['status'] ?? '')) === 'active' ? 'active' : 'suspended';
        $role = (string) ($input['role'] ?? '');
        if ($do === 'bulk_role') {
            if (!in_array($role, self::ASSIGNABLE, true)) {
                return [false, 'ভূমিকা বেছে নিন।'];
            }

            // Same superadmin gate as setRole(): bulk must not open a door
            // the single-row path keeps shut.
            if ($role === Identity::ROLE_SUPERADMIN
                && !$this->checkPermission($identity, 'admin-staff-manage-superadmin')) {
                return [false, 'সুপারএডমিন ভূমিকা দেওয়ার অনুমতি নেই।'];
            }
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
            return [false, 'কোনো স্টাফ নির্বাচন করা হয়নি।'];
        }

        $changed = 0;
        $skipped = 0;
        $touched = [];

        foreach ($ids as $id) {
            $subject = $this->users->findById($id, true);
            $trashed = $subject !== null && $subject['deleted_at'] !== null;

            $refused = $subject === null
                || !in_array((string) $subject['role'], ['admin', 'staff', 'superadmin'], true)
                || !$this->canModify($identity, $subject)
                || ($do === 'bulk_restore' ? !$trashed : $trashed);

            if (!$refused) {
                $refused = match ($do) {
                    'bulk_status' => (string) $subject['status'] === $status
                        || !$this->guardedAgainstLastSuperAdmin(
                            $subject,
                            (string) $subject['role'],
                            (string) $subject['role'],
                            $status,
                        ),
                    'bulk_role' => (string) $subject['role'] === $role
                        || !$this->guardedAgainstLastSuperAdmin(
                            $subject,
                            (string) $subject['role'],
                            $role,
                            (string) $subject['status'],
                        ),
                    // Trashing a lone superadmin removes the platform's last
                    // authority just as surely as demoting or suspending one,
                    // so the same rule answers here.
                    'bulk_trash' => !$this->guardedAgainstLastSuperAdmin(
                        $subject,
                        (string) $subject['role'],
                        (string) $subject['role'],
                        'suspended',
                    ),
                    default => false,
                };
            }

            if ($refused) {
                $skipped++;
                continue;
            }

            if ($do === 'bulk_status') {
                $this->users->update($id, ['status' => $status]);
                $ok = true;
                $logAction = 'admin.status_changed';
                $logDescription = sprintf(
                    'Status %s -> %s for %s (bulk)',
                    (string) $subject['status'],
                    $status,
                    (string) $subject['username'],
                );
            } elseif ($do === 'bulk_role') {
                $this->users->setRole($id, $role, (string) $subject['status']);
                $ok = true;
                $logAction = 'admin.role_changed';
                $logDescription = sprintf(
                    'Role %s -> %s for %s (bulk)',
                    (string) $subject['role'],
                    $role,
                    (string) $subject['username'],
                );
            } elseif ($do === 'bulk_trash') {
                $ok = $this->users->softDelete($id);
                $logAction = 'admin.staff.trashed';
                $logDescription = sprintf('%s moved to trash (bulk)', (string) $subject['username']);
            } else {
                $ok = $this->users->restore($id);
                $logAction = 'admin.staff.restored';
                $logDescription = sprintf('%s restored from trash (bulk)', (string) $subject['username']);
            }

            if (!$ok) {
                $skipped++;
                continue;
            }

            $changed++;
            $touched[] = $id;
            $this->log($identity, $id, $logAction, $logDescription);
        }

        $message = match ($do) {
            'bulk_status' => sprintf(
                'নির্বাচিত স্টাফদের মধ্যে %d জনের অবস্থা %s হয়েছে।',
                $changed,
                $status === 'active' ? 'সক্রিয়' : 'নিষ্ক্রিয়',
            ),
            'bulk_role' => sprintf(
                'নির্বাচিত স্টাফদের মধ্যে %d জনের ভূমিকা হয়েছে %s।',
                $changed,
                UserRepository::ROLE_LABELS[$role] ?? $role,
            ),
            'bulk_trash' => sprintf('নির্বাচিত স্টাফদের মধ্যে %d জন ট্র্যাশে পাঠানো হয়েছে।', $changed),
            default => sprintf('নির্বাচিত স্টাফদের মধ্যে %d জন ফিরিয়ে আনা হয়েছে।', $changed),
        };
        if ($skipped > 0) {
            $message .= " {$skipped} জন বাদ পড়েছে — নিজের অ্যাকাউন্ট, উচ্চতর ভূমিকা, অনুপযুক্ত অবস্থা বা ইতিমধ্যে একই ফলাফল।";
        }

        return [$changed > 0, $message];
    }

    /**
     * Bring one trashed roster row back. The single-row form posts
     * `do=restore`; the permission and rank gates already ran in apply().
     *
     * @param array<string, mixed> $subject
     * @return array{0: bool, 1: string}
     */
    private function restoreStaff(array $subject, Identity $identity): array
    {
        if ($subject['deleted_at'] === null) {
            return [false, 'এই অ্যাকাউন্ট ট্র্যাশে নেই।'];
        }

        $this->users->restore((int) $subject['id']);
        $this->log(
            $identity,
            (int) $subject['id'],
            'admin.staff.restored',
            sprintf('%s restored from trash', (string) $subject['username']),
        );

        return [true, sprintf('%s ফিরিয়ে আনা হয়েছে।', (string) $subject['username'])];
    }

    /**
     * The filter state a bulk run was picked within, as a query string, so
     * the redirect lands back on the same page of the same search.
     *
     * @param array<string, mixed> $input
     */
    private function bulkReturnQuery(array $input): string
    {
        $query = [];

        $q = trim((string) ($input['return_q'] ?? ''));
        if ($q !== '') {
            $query['q'] = $q;
        }

        $trashed = (string) ($input['return_trashed'] ?? '');
        if (in_array($trashed, [UserRepository::DELETED_ONLY, UserRepository::DELETED_ALL], true)) {
            $query['trashed'] = $trashed;
        }

        $page = (int) ($input['return_page'] ?? 0);
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        $sort = (string) ($input['return_sort'] ?? '');
        if (in_array($sort, ['username', 'email', 'role', 'status', 'last_login_at'], true)) {
            $query['sort'] = $sort;
        }

        $dir = strtolower((string) ($input['return_dir'] ?? ''));
        if (in_array($dir, ['asc', 'desc'], true)) {
            $query['dir'] = $dir;
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $subject
     * @return array{0: bool, 1: string}
     */
    private function setStatus(array $input, Identity $identity, array $subject): array
    {
        $status = (string) ($input['status'] ?? '') === 'active' ? 'active' : 'suspended';

        if ((string) $subject['status'] === $status) {
            return [true, 'অবস্থা অপরিবর্তিত আছে।'];
        }

        if (
            !$this->guardedAgainstLastSuperAdmin(
                $subject,
                (string) $subject['role'],
                (string) $subject['role'],
                $status,
            )
        ) {
            return [false, 'শেষ সুপারএডমিনকে নিষ্ক্রিয় করা যাবে না।'];
        }

        $this->users->update((int) $subject['id'], ['status' => $status]);
        $this->log(
            $identity,
            (int) $subject['id'],
            'admin.status_changed',
            sprintf('Status %s -> %s for %s', (string) $subject['status'], $status, (string) $subject['username']),
        );

        return [true, $status === 'active'
            ? 'অ্যাকাউন্ট আবার সক্রিয় করা হয়েছে।'
            : 'অ্যাকাউন্ট নিষ্ক্রিয় করা হয়েছে।'];
    }

    /**
     * Set a colleague's password from here.
     *
     * Not a convenience feature — it is the recovery path. Without it, an
     * operator who locks themselves out has exactly two options (mail somebody,
     * or get a super-admin into a database), and in practice it means the
     * panel ends up with shared credentials, which defeats having roles at all.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $subject
     * @return array{0: bool, 1: string}
     */
    private function resetPassword(array $input, array $subject): array
    {
        $password = (string) ($input['password'] ?? '');

        if (strlen($password) < 8) {
            return [false, 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।'];
        }

        $this->users->update((int) $subject['id'], [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return [true, sprintf('%s-এর পাসওয়ার্ড পরিবর্তন হয়েছে।', (string) $subject['username'])];
    }

    /**
     * Check if identity has the required permission.
     */
    private function checkPermission(Identity $identity, string $permission): bool
    {
        return $this->permissions->hasPermission($identity, $permission);
    }

    /**
     * Refuse a change that would leave the platform with no super-admin.
     *
     * @param array<string, mixed> $subject
     */
    private function guardedAgainstLastSuperAdmin(
        array $subject,
        string $fromRole,
        string $toRole,
        string $toStatus,
    ): bool {
        $losesAuthority = $fromRole === Identity::ROLE_SUPERADMIN
            && ($toRole !== Identity::ROLE_SUPERADMIN || $toStatus !== 'active');
        if (!$losesAuthority) {
            return true;
        }

        return $this->users->hasOtherSuperAdmin((int) $subject['id']);
    }

    /**
     * Each operator's earned / withdrawn / available, keyed by user id.
     *
     * Read from the ledger rather than from each account's balance column,
     * because the question this page answers is "what has this person earned",
     * and the balance also contains money that arrived from somewhere else
     * entirely. One grouped query, not one per admin.
     *
     * @return array<int, array<string, float|int>>
     */
    private function earnings(): array
    {
        $map = [];
        foreach ($this->ledger->adminEarnings() as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /**
     * Restore all trashed staff accounts.
     *
     * @return array{0: bool, 1: string}
     */
    private function restoreAllStaff(Identity $identity): array
    {
        $count = $this->users->restoreAll();

        if ($count === 0) {
            return [false, 'ট্র্যাশে কোনো স্টাফ নেই।'];
        }

        $this->log(
            $identity,
            0,
            'admin.staff.restored_all',
            "All {$count} trashed staff account(s) restored",
        );

        return [true, "{$count} জন স্টাফ ফিরিয়ে আনা হয়েছে।"];
    }

    private function log(Identity $actor, int $subjectId, string $action, string $description): void
    {
        $this->logs->create([
            'user_id' => $actor->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'metadata' => ['subject_id' => $subjectId],
        ]);
    }
}
