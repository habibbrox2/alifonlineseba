<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/staff — the super-admin's roster of operators.
 *
 * A platform with one operator does not need this page; a platform with several
 * does, and the moment it needs it is the moment somebody asks "who can approve
 * an order and who can pay it out?". The roster is the answer, and it is
 * deliberately a page rather than a settings dropdown, because an authority
 * that is changed quietly is an authority nobody knows exists.
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

    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private TransactionRepository $ledger,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            [$ok, $message] = $this->apply((string) ($input['do'] ?? ''), $input, $identity);
            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/staff']);
        }

        return $this->view->render('site/admin/staff.twig', [
            'staff' => $this->users->listStaff(),
            'earnings' => $this->earnings(),
            'roleLabels' => UserRepository::ROLE_LABELS,
            'identity' => $identity,
        ]);
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

        return match ($do) {
            'role' => $this->setRole($input, $identity, $subject),
            'status' => $this->setStatus($input, $identity, $subject),
            'password' => $this->resetPassword($input, $subject),
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
