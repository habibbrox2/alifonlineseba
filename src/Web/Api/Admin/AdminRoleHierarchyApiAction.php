<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\RoleRankRepository;
use App\Service\Api;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminRoleHierarchyApiAction
{
    public function __construct(
        private RoleRankRepository $roleRanks,
        private PermissionService $permissions,
        private ActivityLogRepository $logs,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$this->permissions->canAccessRoute($identity, 'admin-role-hierarchy')) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            if (!$this->permissions->canAccessRoute($identity, 'admin-role-hierarchy-update')) {
                return Api::forbidden();
            }

            $input = (array) $request->getParsedBody();
            $do = (string) ($input['do'] ?? '');

            if ($do === 'reset') {
                $this->roleRanks->reset();
                $this->logs->create([
                    'user_id' => $identity->id,
                    'action' => 'admin.role_hierarchy_reset',
                    'description' => 'Role hierarchy reset to defaults',
                    'metadata' => [],
                ]);
                return Api::ok(['message' => 'র‌্যাংক ডিফল্টে রিসেট করা হয়েছে।']);
            }

            if ($do === 'save_ranks') {
                $newRanks = [];
                foreach (RoleRankRepository::DEFAULT_ROLE_RANK as $role => $defaultRank) {
                    $newRank = (int) ($input[$role] ?? $defaultRank);
                    if ($newRank < 0 || $newRank > 1000) {
                        return Api::fail("Invalid rank for {$role}");
                    }
                    $newRanks[$role] = $newRank;
                }
                $this->roleRanks->updateMany($newRanks, $identity->id);
                $this->logs->create([
                    'user_id' => $identity->id,
                    'action' => 'admin.role_hierarchy_updated',
                    'description' => 'Role hierarchy updated',
                    'metadata' => ['new_ranks' => $newRanks],
                ]);
                return Api::ok(['message' => 'র‌্যাংক সফলভাবে সংরক্ষিত হয়েছে।']);
            }

            return Api::fail('অজানা অ্যাকশন।');
        }

        $ranks = $this->roleRanks->all();
        arsort($ranks);

        return Api::ok([
            'ranks' => $ranks,
            'defaultRanks' => RoleRankRepository::DEFAULT_ROLE_RANK,
        ]);
    }
}
