<?php

declare(strict_types=1);

namespace App\Twig;

use App\Auth\Identity;
use App\Service\PermissionService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for permission checks in templates.
 */
final class PermissionExtension extends AbstractExtension
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can', [$this, 'can']),
            new TwigFunction('can_access', [$this, 'canAccess']),
            new TwigFunction('can_manage', [$this, 'canManage']),
        ];
    }

    /**
     * Check if current user has a specific permission.
     *
     * Usage in template: {% if can('users', 'create') %}...{% endif %}
     */
    public function can(Identity $identity, string $category, string $action, ?Identity $target = null): bool
    {
        return $this->permissionService->can($identity, $category, $action, $target);
    }

    /**
     * Check if current user can access a specific route.
     *
     * Usage in template: {% if can_access('admin-users') %}...{% endif %}
     */
    public function canAccess(Identity $identity, string $route): bool
    {
        return $this->permissionService->canAccessRoute($identity, $route);
    }

    /**
     * Check if current user can manage a target user.
     *
     * Usage in template: {% if can_manage(targetUser) %}...{% endif %}
     */
    public function canManage(Identity $identity, Identity $target): bool
    {
        return $identity->canManage($target);
    }
}