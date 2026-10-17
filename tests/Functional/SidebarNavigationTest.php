<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Twig\TwigExtension;
use Codeception\Test\Unit;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function in_array;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The sidebar has to survive a superadmin's six groups and ~25 links inside a
 * ~830px column, and it has to describe its own collapse state honestly.
 *
 * These render the real partial through a real Twig environment with a
 * controlled permission set, because every rule here lives in the template: a
 * test that only measured the action would keep passing after someone opened a
 * group unconditionally or hardcoded `aria-expanded` again.
 */
final class SidebarNavigationTest extends Unit
{
    /**
     * Every admin route the partial asks about, so a test can stand in for the
     * full-permission case without listing them out by hand.
     */
    private const ALL_ADMIN_ROUTES = [
        'admin',
        'admin-services',
        'admin-categories',
        'admin-staff',
        'admin-users',
        'admin-orders',
        'admin-ledger',
        'admin-topups',
        'admin-withdraws',
        'admin-referrals',
        'admin-transactions',
        'admin-settings-edit',
        'admin-role-hierarchy',
        'admin-permissions-matrix',
        'admin-logs',
        'admin-notifications',
    ];

    public function testOnlyTheGroupHoldingTheCurrentPageStartsOpen(): void
    {
        $html = $this->render(self::ALL_ADMIN_ROUTES, '/admin/services', true);

        // Group order: সার্ভিস হাব, অ্যাকাউন্ট, পরিচালনা, অর্থনীতি, সেটিংস, নিরীক্ষণ.
        // পরিচালনা holds সার্ভিস ম্যানেজমেন্ট, which is the current page.
        assertSame(
            ['false', 'false', 'true', 'false', 'false', 'false'],
            $this->openStates($html),
            'Opening every group rendered 1478px of nav into an ~830px column, '
                . 'which put the current page 630px down. Only the group the '
                . 'visitor is in should be open on arrival.',
        );
    }

    public function testACustomerGetsOnlyTheirTwoGroupsAndTheRightOneOpen(): void
    {
        $html = $this->render([], '/recharge');

        assertSame(['সার্ভিস হাব', 'অ্যাকাউন্ট'], $this->groupLabels($html));
        assertSame(['false', 'true'], $this->openStates($html));
    }

    /**
     * A permission set that reaches one admin area and not the others used to
     * leave "অর্থনীতি", "সেটিংস" and "নিরীক্ষণ" on screen with nothing under
     * them: headers promising pages that do not exist for this account, which
     * reads as a broken menu rather than a restricted one.
     */
    public function testAdminGroupsWithNoReachablePagesAreNotRendered(): void
    {
        $html = $this->render(['admin-services'], '/services', true);

        assertSame(
            ['সার্ভিস হাব', 'অ্যাকাউন্ট', 'পরিচালনা'],
            $this->groupLabels($html),
            'Only the admin group holding a reachable page may appear.',
        );
        assertStringNotContainsString('অর্থনীতি', $html);
        assertStringNotContainsString('নিরীক্ষণ', $html);
        assertStringContainsString('সার্ভিস ম্যানেজমেন্ট', $html);
    }

    /**
     * `aria-expanded` used to be a literal "true" on every button, so a screen
     * reader was told a collapsed group was open — and stayed wrong after every
     * toggle. It has to be bound to the state the visitor can actually change.
     */
    public function testTheCollapseButtonsReportTheirRealState(): void
    {
        $html = $this->render(self::ALL_ADMIN_ROUTES, '/admin/settings', true);

        assertStringNotContainsString(
            'aria-expanded="true"',
            $html,
            'A hardcoded aria-expanded cannot track the toggle and lies after the first click.',
        );
        assertSame(
            6,
            substr_count($html, ':aria-expanded="open.toString()"'),
            'Every group header button has to carry the bound attribute.',
        );
        assertSame(
            ['false', 'false', 'false', 'false', 'true', 'false'],
            $this->openStates($html),
            'সেটিংস holds সাইট সেটিংস, the current page.',
        );
    }

    /**
     * Green is how the sidebar says "you are here". Six coloured group icons
     * spent it up the column before the active row got a look, and the icon
     * next to the label was pure noise once the divider was doing the grouping.
     */
    public function testTheActiveRowStaysTheStrongestThingInTheColumn(): void
    {
        $html = $this->render(self::ALL_ADMIN_ROUTES, '/admin/services', true);

        assertStringContainsString('nav-link nav-link-active', $html);
        assertStringContainsString('aria-current="page"', $html);
        assertStringNotContainsString(
            'sidebar-group-icon',
            $html,
            'The group-header glyph was removed; the divider and the label carry the grouping.',
        );
    }

    /**
     * @param list<string> $routes Routes this account may reach.
     * @param bool         $isAdmin Whether canAccessAdmin() admits them to the
     *                             admin groups at all — a separate fact from any
     *                             one route, since an admin holding a narrow set
     *                             of permissions reaches none of them and still
     *                             sees the admin half of the menu.
     */
    private function render(array $routes, string $currentPath, bool $isAdmin = false): string
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/resources/views'),
            ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false],
        );
        $twig->addExtension(new TwigExtension());

        // Stands in for PermissionExtension, which is type-hinted to the real
        // Identity and reads the role matrix. The tests vary the permission set,
        // so that is the only part of it worth reproducing here.
        $twig->addFunction(new \Twig\TwigFunction(
            'can_access',
            static fn (object $identity, string $route): bool => in_array($route, $routes, true),
        ));

        $identity = new class ($isAdmin) {
            public function __construct(private bool $admin) {}

            public function canAccessAdmin(): bool
            {
                return $this->admin;
            }

            public function __get(string $name): mixed
            {
                return null;
            }
        };

        return $twig->render('partials/sidebar.twig', [
            'identity' => $identity,
            'csrf' => 'token',
            'unread' => 0,
            'currentPath' => $currentPath,
            'seoUrl' => 'https://example.test',
        ]);
    }

    /** @return list<string> */
    private function groupLabels(string $html): array
    {
        preg_match_all('/sidebar-group-header.*?>(?:<svg.*?<\/svg>)?\s*(.*?)\s*<svg/s', $html, $m);

        return array_values(array_map('trim', $m[1]));
    }

    /** @return list<string> */
    private function openStates(string $html): array
    {
        preg_match_all('/sidebar-group"\s+x-data="\{ open: (true|false) \}"/', $html, $m);

        return $m[1];
    }
}
