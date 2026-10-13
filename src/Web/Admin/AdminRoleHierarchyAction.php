<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\RoleRankRepository;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET /admin/role-hierarchy — view and manage the role hierarchy.
 *
 * Superadmin only: this page shows the RBAC hierarchy and allows
 * reordering role ranks (which controls who can manage whom).
 *
 * The hierarchy is:
 * superadmin (100) > admin (50) > staff (25) > user (10)
 *
 * Higher rank can manage lower ranks. Self-management is always blocked.
 *
 * Changes are persisted to the database and take effect immediately.
 */
final readonly class AdminRoleHierarchyAction
{
    public function __construct(
        private WebViewRenderer $view,
        private RoleRankRepository $roleRanks,
        private ActivityLogRepository $logs,
        private PermissionService $permissions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            [$ok, $message] = $this->apply($input, $identity);
            $request->getAttribute('flash')?->set($ok ? 'success' : 'error', $message);

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/role-hierarchy']);
        }

        // Get ranks from database (with defaults fallback)
        $ranks = $this->roleRanks->all();

        // Sort by rank descending for display
        arsort($ranks);

        return $this->view->render('site/admin/role-hierarchy.twig', [
            'ranks' => $ranks,
            'defaultRanks' => RoleRankRepository::DEFAULT_ROLE_RANK,
            'roleLabels' => [
                'superadmin' => 'সুপারএডমিন',
                'admin' => 'এডমিন',
                'staff' => 'স্টাফ',
                'user' => 'ইউজার',
            ],
            'identity' => $identity,
        ]);
    }

    /**
     * Check if identity has the required permission.
     */
    private function checkPermission(Identity $identity, string $permission): bool
    {
        return $this->permissions->hasPermission($identity, $permission);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: bool, 1: string}
     */
    private function apply(array $input, Identity $identity): array
    {
        $do = (string) ($input['do'] ?? '');

        if (!$this->checkPermission($identity, 'admin-role-hierarchy-update')) {
            return [false, 'আপনার এই অ্যাকশন করার অনুমতি নেই।'];
        }

        return match ($do) {
            'save_ranks' => $this->saveRanks($input, $identity),
            'reset' => $this->resetRanks($identity),
            default => [false, 'অজানা অ্যাকশন।'],
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: bool, 1: string}
     */
    private function saveRanks(array $input, Identity $identity): array
    {
        $newRanks = [];
        $hasChanges = false;

        foreach (RoleRankRepository::DEFAULT_ROLE_RANK as $role => $defaultRank) {
            $newRank = (int) ($input[$role] ?? $defaultRank);
            if ($newRank < 0 || $newRank > 1000) {
                return [false, sprintf('%s-এর র‍্যাঙ্ক ০ থেকে ১০০০-এর মধ্যে হতে হবে।', $role)];
            }
            if ($newRank !== $defaultRank) {
                $hasChanges = true;
            }
            $newRanks[$role] = $newRank;
        }

        // Validate hierarchy: superadmin must be highest
        if ($newRanks['superadmin'] <= max($newRanks['admin'], $newRanks['staff'], $newRanks['user'])) {
            return [false, 'সুপারএডমিনের র‍্যাঙ্ক সবচেয়ে বেশি হতে হবে।'];
        }

        // Admin must be higher than staff and user
        if ($newRanks['admin'] <= max($newRanks['staff'], $newRanks['user'])) {
            return [false, 'এডমিনের র‍্যাঙ্ক স্টাফ এবং ইউজারের চেয়ে বেশি হতে হবে।'];
        }

        // Staff must be higher than user
        if ($newRanks['staff'] <= $newRanks['user']) {
            return [false, 'স্টাফের র‍্যাঙ্ক ইউজারের চেয়ে বেশি হতে হবে।'];
        }

        // No duplicate ranks
        $uniqueRanks = array_unique($newRanks);
        if (count($uniqueRanks) !== count($newRanks)) {
            return [false, 'প্রতিটি ভূমিকার র‍্যাঙ্ক অনন্য হতে হবে।'];
        }

        if (!$hasChanges) {
            return [true, 'র‍্যাঙ্ক অপরিবর্তিত আছে।'];
        }

        // Persist to database
        $this->roleRanks->updateMany($newRanks, $identity->id);

        // Log the change
        $this->logs->create([
            'user_id' => $identity->id,
            'action' => 'admin.role_hierarchy_updated',
            'description' => 'ভূমিকার র‍্যাঙ্ক আপডেট করা হয়েছে।',
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'metadata' => ['new_ranks' => $newRanks],
        ]);

        return [true, 'ভূমিকার র‍্যাঙ্ক আপডেট করা হয়েছে। পরিবর্তনগুলি তাৎক্ষণিকভাবে কার্যকর হবে।'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function resetRanks(Identity $identity): array
    {
        $this->roleRanks->reset();

        // Log the reset
        $this->logs->create([
            'user_id' => $identity->id,
            'action' => 'admin.role_hierarchy_reset',
            'description' => 'ভূমিকার র‍্যাঙ্ক ডিফল্ট মানে ফিরিয়ে আনা হয়েছে।',
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'metadata' => [],
        ]);

        return [true, 'ভূমিকার র‍্যাঙ্ক ডিফল্ট মানে ফিরিয়ে আনা হয়েছে।'];
    }
}