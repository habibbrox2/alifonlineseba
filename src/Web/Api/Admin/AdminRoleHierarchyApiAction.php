<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminRoleHierarchyApiAction
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->isSuperAdmin()) {
            return Api::forbidden();
        }
        return Api::ok(['message' => 'Role hierarchy endpoint available']);
    }
}
