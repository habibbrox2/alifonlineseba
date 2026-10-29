<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\RoleRankRepository;
use App\Service\Api;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminPermissionsMatrixApiAction
{
    public function __construct(
        private RoleRankRepository $roleRanks,
        private PermissionService $permissions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$this->permissions->canAccessRoute($identity, 'admin-permissions-matrix')) {
            return Api::forbidden();
        }

        $roleRanks = $this->roleRanks->all();
        arsort($roleRanks);

        $matrix = [];
        foreach (array_keys($roleRanks) as $role) {
            $matrix[$role] = $this->permissions->getRolePermissions($role);
        }

        return Api::ok([
            'matrix' => $matrix,
            'ranks' => $roleRanks,
            'categories' => PermissionService::getCategories(),
        ]);
    }
}
