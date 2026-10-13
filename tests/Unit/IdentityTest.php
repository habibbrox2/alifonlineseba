<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\Identity;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

/**
 * Test the role ranking RBAC system in Identity.
 * Hierarchy: superadmin (100) > admin (50) > staff (25) > user (10)
 */
final class IdentityTest extends Unit
{
    private function makeIdentity(string $role, int $id = 1): Identity
    {
        return new Identity(
            id: $id,
            username: 'test',
            phone: '01712345678',
            email: 'test@example.com',
            role: $role,
            status: 'active',
            balance: 0.0,
            avatar: null,
        );
    }

    public function testSuperAdminHasHighestRank(): void
    {
        $superAdmin = $this->makeIdentity('superadmin');
        $admin = $this->makeIdentity('admin');
        $staff = $this->makeIdentity('staff');
        $user = $this->makeIdentity('user');

        assertEquals(100, $superAdmin->getRoleRank());
        assertEquals(50, $admin->getRoleRank());
        assertEquals(25, $staff->getRoleRank());
        assertEquals(10, $user->getRoleRank());
    }

    public function testSuperAdminCanManageAllRoles(): void
    {
        $superAdmin = $this->makeIdentity('superadmin', 1);

        assertTrue($superAdmin->canManageRole('admin'));
        assertTrue($superAdmin->canManageRole('staff'));
        assertTrue($superAdmin->canManageRole('user'));
        assertTrue($superAdmin->canManageRole('unknown')); // unknown role defaults to 0
    }

    public function testAdminCanManageStaffAndUserButNotSuperAdmin(): void
    {
        $admin = $this->makeIdentity('admin', 1);

        assertTrue($admin->canManageRole('staff'));
        assertTrue($admin->canManageRole('user'));
        assertFalse($admin->canManageRole('superadmin'));
        assertFalse($admin->canManageRole('admin')); // equal rank
    }

    public function testStaffCanManageUserButNotAdminOrSuperAdmin(): void
    {
        $staff = $this->makeIdentity('staff', 1);

        assertTrue($staff->canManageRole('user'));
        assertFalse($staff->canManageRole('admin'));
        assertFalse($staff->canManageRole('superadmin'));
        assertFalse($staff->canManageRole('staff')); // equal rank
    }

    public function testUserCannotManageAnyRole(): void
    {
        $user = $this->makeIdentity('user', 1);

        assertFalse($user->canManageRole('superadmin'));
        assertFalse($user->canManageRole('admin'));
        assertFalse($user->canManageRole('staff'));
        assertFalse($user->canManageRole('user')); // equal rank
    }

    public function testCannotManageSelf(): void
    {
        $identity = $this->makeIdentity('superadmin', 1);
        $sameId = $this->makeIdentity('user', 1); // same ID

        assertFalse($identity->canManage($sameId));
    }

    public function testCanManageDifferentUserWithLowerRole(): void
    {
        $superAdmin = $this->makeIdentity('superadmin', 1);
        $user = $this->makeIdentity('user', 2);

        assertTrue($superAdmin->canManage($user));
    }

    public function testCannotManageDifferentUserWithEqualOrHigherRole(): void
    {
        $admin = $this->makeIdentity('admin', 1);
        $otherAdmin = $this->makeIdentity('admin', 2);
        $superAdmin = $this->makeIdentity('superadmin', 3);

        assertFalse($admin->canManage($otherAdmin)); // equal rank
        assertFalse($admin->canManage($superAdmin)); // higher rank
    }

    public function testUnknownRoleDefaultsToRankZero(): void
    {
        $identity = new Identity(
            id: 1,
            username: 'test',
            phone: '01712345678',
            email: 'test@example.com',
            role: 'unknown_role',
            status: 'active',
            balance: 0.0,
            avatar: null,
        );

        assertEquals(0, $identity->getRoleRank());
        assertFalse($identity->canManageRole('user')); // user rank 10 > 0
    }
}