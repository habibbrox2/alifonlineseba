<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Repository\RoleRankRepository;

/**
 * Centralized permission checking service.
 *
 * Uses the role hierarchy (from database) and the default permissions matrix
 * to determine if an identity has a specific permission.
 */
final class PermissionService
{
    /** Default permissions matrix matching Identity capabilities */
    private const DEFAULT_PERMISSIONS = [
        'superadmin' => [
            'users' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true, 'manage_roles' => true],
            'roles' => ['view_hierarchy' => true, 'edit_hierarchy' => true, 'view_matrix' => true],
            'orders' => ['view' => true, 'approve' => true, 'reject' => true, 'bulk_action' => true],
            'finance' => ['view_ledger' => true, 'process_withdrawals' => true, 'process_topups' => true, 'process_recharges' => true],
            'settings' => ['view_site_settings' => true, 'edit_site_settings' => true, 'manage_recharge_rules' => true],
            'logs' => ['view_activity_logs' => true, 'export_logs' => true],
            'notifications' => ['send' => true, 'retry_failed' => true, 'delete_failed' => true],
            'staff' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true, 'manage_superadmin' => true],
            'services' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'categories' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'referrals' => ['view' => true, 'manage' => true],
        ],
        'admin' => [
            'users' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true, 'manage_roles' => true],
            'roles' => ['view_hierarchy' => true, 'edit_hierarchy' => false, 'view_matrix' => true],
            'orders' => ['view' => true, 'approve' => true, 'reject' => true, 'bulk_action' => true],
            'finance' => ['view_ledger' => true, 'process_withdrawals' => false, 'process_topups' => true, 'process_recharges' => true],
            'settings' => ['view_site_settings' => true, 'edit_site_settings' => false, 'manage_recharge_rules' => false],
            'logs' => ['view_activity_logs' => true, 'export_logs' => false],
            'notifications' => ['send' => true, 'retry_failed' => true, 'delete_failed' => true],
            'staff' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false, 'manage_superadmin' => false],
            'services' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'categories' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'referrals' => ['view' => true, 'manage' => false],
        ],
        'staff' => [
            'users' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false, 'manage_roles' => false],
            'roles' => ['view_hierarchy' => true, 'edit_hierarchy' => false, 'view_matrix' => true],
            'orders' => ['view' => true, 'approve' => true, 'reject' => false, 'bulk_action' => false],
            'finance' => ['view_ledger' => false, 'process_withdrawals' => false, 'process_topups' => false, 'process_recharges' => false],
            'settings' => ['view_site_settings' => false, 'edit_site_settings' => false, 'manage_recharge_rules' => false],
            'logs' => ['view_activity_logs' => false, 'export_logs' => false],
            'notifications' => ['send' => false, 'retry_failed' => false, 'delete_failed' => false],
            'staff' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false, 'manage_superadmin' => false],
            'services' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            'categories' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            'referrals' => ['view' => true, 'manage' => false],
        ],
        'user' => [
            'users' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false, 'manage_roles' => false],
            'roles' => ['view_hierarchy' => false, 'edit_hierarchy' => false, 'view_matrix' => false],
            'orders' => ['view' => false, 'approve' => false, 'reject' => false, 'bulk_action' => false],
            'finance' => ['view_ledger' => false, 'process_withdrawals' => false, 'process_topups' => false, 'process_recharges' => false],
            'settings' => ['view_site_settings' => false, 'edit_site_settings' => false, 'manage_recharge_rules' => false],
            'logs' => ['view_activity_logs' => false, 'export_logs' => false],
            'notifications' => ['send' => false, 'retry_failed' => false, 'delete_failed' => false],
            'staff' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false, 'manage_superadmin' => false],
            'services' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
            'categories' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
            'referrals' => ['view' => false, 'manage' => false],
        ],
    ];

    public function __construct(
        private readonly RoleRankRepository $roleRanks,
    ) {}

    /**
     * Check if an identity has a specific permission.
     *
     * @param string $category Permission category (e.g., 'users', 'orders', 'finance')
     * @param string $action Permission action (e.g., 'create', 'read', 'approve', 'view_ledger')
     * @param Identity|null $target Optional target identity for cross-user checks (e.g., can this user manage that user?)
     */
    public function can(Identity $identity, string $category, string $action, ?Identity $target = null): bool
    {
        // Superadmin always has all permissions
        if ($identity->isSuperAdmin()) {
            return true;
        }

        // Get the role's permissions from the matrix
        $rolePermissions = self::DEFAULT_PERMISSIONS[$identity->role][$category] ?? [];

        // Check base permission
        $hasPermission = $rolePermissions[$action] ?? false;

        if (!$hasPermission) {
            return false;
        }

        // Additional checks for cross-user operations
        if ($target !== null) {
            // Cannot manage self
            if ($identity->id === $target->id) {
                return false;
            }

            // For role management actions, check role hierarchy
            if (in_array($action, ['manage_roles', 'update', 'delete'], true)) {
                return $identity->canManageRole($target->role);
            }

            // For staff management, check if target is superadmin and current user is not
            if ($category === 'staff' && $target->isSuperAdmin() && !$identity->isSuperAdmin()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if identity can access a specific admin page/route.
     */
    public function canAccessRoute(Identity $identity, string $route): bool
    {
        return match ($route) {
            // The admin panel landing page itself, which the sidebar links to.
            'admin' => $identity->canAccessAdmin(),
            'admin-users' => $this->can($identity, 'users', 'read'),
            'admin-user-create' => $this->can($identity, 'users', 'create'),
            'admin-user-edit' => $this->can($identity, 'users', 'update'),
            'admin-user-delete' => $this->can($identity, 'users', 'delete'),
            'admin-user-manage-roles' => $this->can($identity, 'users', 'manage_roles'),
            'admin-user-status-update' => $this->can($identity, 'users', 'update'),
            'admin-user-role-update' => $this->can($identity, 'users', 'manage_roles'),
            'admin-user-password-reset' => $this->can($identity, 'users', 'update'),
            'admin-user-restore' => $this->can($identity, 'users', 'update'),
            // The user desk posts plural route names; the API actions use the
            // singular spellings above. Both must resolve or the check falls
            // through to `default => false` and denies even a superadmin.
            'admin-users-create' => $this->can($identity, 'users', 'create'),
            'admin-users-read' => $this->can($identity, 'users', 'read'),
            'admin-users-edit' => $this->can($identity, 'users', 'update'),
            'admin-users-update' => $this->can($identity, 'users', 'update'),
            'admin-users-delete' => $this->can($identity, 'users', 'delete'),
            'admin-users-manage-roles' => $this->can($identity, 'users', 'manage_roles'),
            'admin-users-role-update' => $this->can($identity, 'users', 'manage_roles'),
            'admin-users-status-update' => $this->can($identity, 'users', 'update'),
            'admin-users-password-reset' => $this->can($identity, 'users', 'update'),
            'admin-users-restore' => $this->can($identity, 'users', 'update'),
            'admin-staff' => $this->can($identity, 'staff', 'read'),
            'admin-staff-create' => $this->can($identity, 'staff', 'create'),
            'admin-staff-update' => $this->can($identity, 'staff', 'update'),
            'admin-staff-delete' => $this->can($identity, 'staff', 'delete'),
            'admin-staff-manage-superadmin' => $this->can($identity, 'staff', 'manage_superadmin'),
            'admin-staff-role-update' => $this->can($identity, 'staff', 'update'),
            'admin-staff-status-update' => $this->can($identity, 'staff', 'update'),
            'admin-staff-password-reset' => $this->can($identity, 'staff', 'update'),
            'admin-role-hierarchy' => $this->can($identity, 'roles', 'view_hierarchy'),
            'admin-role-hierarchy-edit' => $this->can($identity, 'roles', 'edit_hierarchy'),
            'admin-role-hierarchy-update' => $this->can($identity, 'roles', 'edit_hierarchy'),
            'admin-permissions-matrix' => $this->can($identity, 'roles', 'view_matrix'),
            'admin-orders' => $this->can($identity, 'orders', 'view'),
            'admin-order-approve' => $this->can($identity, 'orders', 'approve'),
            'admin-order-reject' => $this->can($identity, 'orders', 'reject'),
            'admin-order-bulk' => $this->can($identity, 'orders', 'bulk_action'),
            'admin-orders-bulk-status' => $this->can($identity, 'orders', 'bulk_action'),
            'admin-orders-bulk-export' => $this->can($identity, 'orders', 'bulk_action'),
            'admin-topups' => $this->can($identity, 'finance', 'process_topups'),
            'admin-topups-bulk-approve' => $this->can($identity, 'finance', 'process_topups'),
            'admin-withdraws' => $this->can($identity, 'finance', 'process_withdrawals'),
            'admin-withdraws-approve' => $this->can($identity, 'finance', 'process_withdrawals'),
            'admin-withdraws-reject' => $this->can($identity, 'finance', 'process_withdrawals'),
            'admin-withdraws-release' => $this->can($identity, 'finance', 'process_withdrawals'),
            'admin-ledger' => $this->can($identity, 'finance', 'view_ledger'),
            'admin-recharges' => $this->can($identity, 'finance', 'process_recharges'),
            'admin-settings' => $this->can($identity, 'settings', 'view_site_settings'),
            'admin-settings-edit' => $this->can($identity, 'settings', 'edit_site_settings'),
            'admin-recharge-rules' => $this->can($identity, 'settings', 'manage_recharge_rules'),
            'admin-logs' => $this->can($identity, 'logs', 'view_activity_logs'),
            'admin-logs-export' => $this->can($identity, 'logs', 'export_logs'),
            'admin-notifications' => $this->can($identity, 'notifications', 'send'),
            'admin-notifications-retry' => $this->can($identity, 'notifications', 'retry_failed'),
            'admin-notifications-delete' => $this->can($identity, 'notifications', 'delete_failed'),
            'admin-services' => $this->can($identity, 'services', 'read'),
            'admin-services-create' => $this->can($identity, 'services', 'create'),
            'admin-services-update' => $this->can($identity, 'services', 'update'),
            'admin-services-delete' => $this->can($identity, 'services', 'delete'),
            'admin-categories' => $this->can($identity, 'categories', 'read'),
            'admin-categories-create' => $this->can($identity, 'categories', 'create'),
            'admin-categories-update' => $this->can($identity, 'categories', 'update'),
            'admin-categories-delete' => $this->can($identity, 'categories', 'delete'),
            'admin-referrals' => $this->can($identity, 'referrals', 'view'),
            'admin-referrals-manage' => $this->can($identity, 'referrals', 'manage'),
            'admin-transactions' => $this->can($identity, 'finance', 'view_ledger'),
            default => false,
        };
    }

    /**
     * Check if identity has a specific permission by route name.
     * Convenience method for middleware/controllers using route-based checks.
     */
    public function hasPermission(Identity $identity, string $route): bool
    {
        return $this->canAccessRoute($identity, $route);
    }

    /**
     * Get all permissions for a role (for matrix display).
     *
     * @return array<string, array<string, bool>>
     */
    public function getRolePermissions(string $role): array
    {
        return self::DEFAULT_PERMISSIONS[$role] ?? [];
    }

    /**
     * Get all permission categories.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            'users' => 'ব্যবহারকারী ব্যবস্থাপনা',
            'roles' => 'ভূমিকা ও অনুমতি',
            'orders' => 'অর্ডার ব্যবস্থাপনা',
            'finance' => 'আর্থিক কার্যক্রম',
            'settings' => 'সেটিংস',
            'logs' => 'গতিবিধি লগ',
            'notifications' => 'বিজ্ঞপ্তি',
            'staff' => 'স্টাফ ব্যবস্থাপনা',
            'services' => 'সেবা',
            'categories' => 'বিভাগ',
            'referrals' => 'রেফারেল',
        ];
    }

    /**
     * Get all permission actions for a category.
     *
     * @return array<string, string>
     */
    public static function getActions(string $category): array
    {
        $allActions = [];
        foreach (self::DEFAULT_PERMISSIONS as $rolePermissions) {
            foreach ($rolePermissions[$category] ?? [] as $action => $granted) {
                if (!isset($allActions[$action])) {
                    $allActions[$action] = self::getActionLabel($action);
                }
            }
        }
        return $allActions;
    }

    /**
     * Get human-readable label for an action.
     */
    private static function getActionLabel(string $action): string
    {
        return match ($action) {
            'create' => 'তৈরি',
            'read' => 'দেখা',
            'update' => 'আপডেট',
            'delete' => 'মুছে ফেলা',
            'manage_roles' => 'ভূমিকা বরাদ্দ',
            'view_hierarchy' => 'পদক্রম দেখুন',
            'edit_hierarchy' => 'পদক্রম সম্পাদনা',
            'view_matrix' => 'ম্যাট্রিক্স দেখুন',
            'approve' => 'অনুমোদন',
            'reject' => 'অনবৈধ',
            'bulk_action' => 'থোক কাজ',
            'view_ledger' => 'লেজার দেখুন',
            'process_withdrawals' => 'উত্তোলন প্রক্রিয়া',
            'process_topups' => 'টপ-আপ প্রক্রিয়া',
            'process_recharges' => 'রিচার্জ প্রক্রিয়া',
            'view_site_settings' => 'সাইট সেটিংস দেখুন',
            'edit_site_settings' => 'সাইট সেটিংস সম্পাদনা',
            'manage_recharge_rules' => 'রিচার্জ নিয়ম',
            'view_activity_logs' => 'গতিবিধি লগ দেখুন',
            'export_logs' => 'লগ রফতানি',
            'send' => 'পাঠান',
            'retry_failed' => 'ব্যর্থ পুনরায় চেষ্টা',
            'manage_superadmin' => 'সুপারএডমিন ব্যবস্থাপনা',
            default => $action,
        };
    }
}