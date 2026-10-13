<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\RoleRankRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET /admin/permissions-matrix — view the detailed permissions matrix.
 *
 * Superadmin only: this page shows what each role can access beyond
 * the management hierarchy (who can manage whom).
 *
 * The matrix includes permissions for:
 * - User management (create, read, update, delete users)
 * - Role management (assign roles, change role hierarchy)
 * - Order management (view, approve, reject orders)
 * - Financial operations (view ledger, process withdrawals, top-ups)
 * - Settings management (site settings, recharge rules)
 * - Activity logs (view, export)
 * - Notifications (send, retry)
 * - Staff management (create, edit staff accounts)
 * - Services & categories (create, edit, delete)
 * - Referrals (view, manage)
 */
final readonly class AdminPermissionsMatrixAction
{
    /** Default permissions matrix matching Identity capabilities */
    private const DEFAULT_PERMISSIONS = [
        'superadmin' => [
            'label' => 'সুপারএডমিন',
            'permissions' => [
                'users' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true, 'manage_roles' => true],
                'roles' => ['view_hierarchy' => true, 'edit_hierarchy' => true, 'view_matrix' => true],
                'orders' => ['view' => true, 'approve' => true, 'reject' => true, 'bulk_action' => true],
                'finance' => ['view_ledger' => true, 'process_withdrawals' => true, 'process_topups' => true, 'process_recharges' => true],
                'settings' => ['view_site_settings' => true, 'edit_site_settings' => true, 'manage_recharge_rules' => true],
                'logs' => ['view_activity_logs' => true, 'export_logs' => true],
                'notifications' => ['send' => true, 'retry_failed' => true],
                'staff' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true, 'manage_superadmin' => true],
                'services' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'categories' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'referrals' => ['view' => true, 'manage' => true],
            ],
        ],
        'admin' => [
            'label' => 'এডমিন',
            'permissions' => [
                'users' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true, 'manage_roles' => true],
                'roles' => ['view_hierarchy' => true, 'edit_hierarchy' => false, 'view_matrix' => true],
                'orders' => ['view' => true, 'approve' => true, 'reject' => true, 'bulk_action' => true],
                'finance' => ['view_ledger' => true, 'process_withdrawals' => false, 'process_topups' => true, 'process_recharges' => true],
                'settings' => ['view_site_settings' => true, 'edit_site_settings' => false, 'manage_recharge_rules' => false],
                'logs' => ['view_activity_logs' => true, 'export_logs' => false],
                'notifications' => ['send' => true, 'retry_failed' => true],
                'staff' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false, 'manage_superadmin' => false],
                'services' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'categories' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'referrals' => ['view' => true, 'manage' => false],
            ],
        ],
        'staff' => [
            'label' => 'স্টাফ',
            'permissions' => [
                'users' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false, 'manage_roles' => false],
                'roles' => ['view_hierarchy' => true, 'edit_hierarchy' => false, 'view_matrix' => true],
                'orders' => ['view' => true, 'approve' => true, 'reject' => false, 'bulk_action' => false],
                'finance' => ['view_ledger' => false, 'process_withdrawals' => false, 'process_topups' => false, 'process_recharges' => false],
                'settings' => ['view_site_settings' => false, 'edit_site_settings' => false, 'manage_recharge_rules' => false],
                'logs' => ['view_activity_logs' => false, 'export_logs' => false],
                'notifications' => ['send' => false, 'retry_failed' => false],
                'staff' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false, 'manage_superadmin' => false],
                'services' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
                'categories' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
                'referrals' => ['view' => true, 'manage' => false],
            ],
        ],
        'user' => [
            'label' => 'ব্যবহারকারী',
            'permissions' => [
                'users' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false, 'manage_roles' => false],
                'roles' => ['view_hierarchy' => false, 'edit_hierarchy' => false, 'view_matrix' => false],
                'orders' => ['view' => false, 'approve' => false, 'reject' => false, 'bulk_action' => false],
                'finance' => ['view_ledger' => false, 'process_withdrawals' => false, 'process_topups' => false, 'process_recharges' => false],
                'settings' => ['view_site_settings' => false, 'edit_site_settings' => false, 'manage_recharge_rules' => false],
                'logs' => ['view_activity_logs' => false, 'export_logs' => false],
                'notifications' => ['send' => false, 'retry_failed' => false],
                'staff' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false, 'manage_superadmin' => false],
                'services' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
                'categories' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
                'referrals' => ['view' => false, 'manage' => false],
            ],
        ],
    ];

    /** Permission category labels for display */
    private const CATEGORY_LABELS = [
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

    /** Permission action labels for display */
    private const ACTION_LABELS = [
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
    ];

    public function __construct(
        private WebViewRenderer $view,
        private RoleRankRepository $roleRanks,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        // Rank order comes from the database, with the built-in defaults as
        // fallback for roles that have no row yet.
        $roleRanks = $this->roleRanks->all();
        Identity::setRoleRanks($roleRanks);

        // Get all roles from database (ordered by rank)
        $roles = $this->getOrderedRoles($roleRanks);

        // Build permissions matrix
        $matrix = $this->buildMatrix($roles);

        return $this->view->render('site/admin/permissions-matrix.twig', [
            'identity' => $identity,
            'roles' => $roles,
            'matrix' => $matrix,
            'categoryLabels' => self::CATEGORY_LABELS,
            'actionLabels' => self::ACTION_LABELS,
            'roleRanks' => $roleRanks,
        ]);
    }

    /**
     * Get roles ordered by rank (highest first)
     */
    private function getOrderedRoles(array $roleRanks): array
    {
        $roles = array_keys($roleRanks);
        usort($roles, fn ($a, $b) => ($roleRanks[$b] ?? 0) <=> ($roleRanks[$a] ?? 0));
        return $roles;
    }

    /**
     * Build the permissions matrix for all roles
     */
    private function buildMatrix(array $roles): array
    {
        $matrix = [];

        foreach ($roles as $role) {
            $matrix[$role] = self::DEFAULT_PERMISSIONS[$role]['permissions'] ?? [];
        }

        return $matrix;
    }
}