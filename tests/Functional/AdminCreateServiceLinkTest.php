<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Twig\TwigExtension;
use App\Web\Admin\AdminServicesAction;
use Codeception\Test\Unit;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The services pages are user-facing, so the "create a new service" shortcut that
 * admins get there is invisible to everyone else — and the category it hands to
 * /admin/services decides which row the create form pre-selects.
 *
 * The view half is checked by rendering the real template through a real Twig
 * environment, because the whole point is the `{% if %}` around the link: a test
 * that only asserted on the action would keep passing after someone moved the
 * anchor outside the guard.
 */
final class AdminCreateServiceLinkTest extends Unit
{
    private const CTA = 'নতুন সার্ভিস তৈরি করুন';
    private const EMPTY_CTA = 'এখানেই নতুন সার্ভিস যোগ করুন';

    /**
     * Stands in for App\Auth\Identity, which needs a session-backed user row.
     * Only what these templates touch is implemented.
     */
    private function identity(bool $admin): object
    {
        return new class ($admin) {
            public function __construct(private bool $admin) {}

            public function canAccessAdmin(): bool
            {
                return $this->admin;
            }

            public function __get(string $name): mixed
            {
                return match ($name) {
                    'username' => 'admin01',
                    'phone' => '01700000000',
                    'balance' => 100,
                    default => null,
                };
            }
        };
    }

    /**
     * The same environment config/common/di/twig.php builds, minus the cache —
     * a test must see the template as it is on disk, not a stale compiled copy.
     */
    private function environment(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/resources/views'),
            ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false],
        );
        $twig->addExtension(new TwigExtension());

        return $twig;
    }

    private function renderServicesPage(bool $admin, array $category, array $services = []): string
    {
        return $this->environment()->render('site/services/category.twig', [
            'identity' => $this->identity($admin),
            'category' => $category,
            'services' => $services,
            'csrf' => 'token',
            'unread' => 0,
            'currentPath' => '/services',
            'seoUrl' => 'https://example.test',
        ]);
    }

    /**
     * Hrefs of the anchors whose visible text is `$label`.
     *
     * Scoped by label on purpose: the page chrome (sidebar, topbar, mobile
     * drawer) also links to /admin/services, so a bare "does this page contain
     * /admin/services" check would pass even with the button's guard removed.
     *
     * @return list<string>
     */
    private function linksLabelled(string $html, string $label): array
    {
        preg_match_all('#<a href="([^"]+)"[^>]*>(?:(?!</a>).)*' . preg_quote($label, '#') . '#s', $html, $matches);
        return $matches[1];
    }

    public function testAdminSeesTheCreateShortcutOnTheServicesRoot(): void
    {
        // /services renders category.twig with a synthetic category that has no id.
        $html = $this->renderServicesPage(true, [
            'name' => 'সকল সার্ভিস',
            'description' => 'All Seba এর সকল ডেমো সার্ভিস একসাথে',
            'icon' => 'layers',
        ]);

        assertStringContainsString(self::CTA, $html);
        assertSame(['/admin/services'], $this->linksLabelled($html, self::CTA));
    }

    public function testCategoryPageCarriesItsIdSoTheFormOpensOnThatCategory(): void
    {
        $html = $this->renderServicesPage(true, [
            'id' => 7,
            'name' => 'মোবাইল রিচার্জ',
            'description' => 'যেকোনো অপারেটর',
            'icon' => 'smartphone',
        ]);

        assertSame(['/admin/services?category=7'], $this->linksLabelled($html, self::CTA));
    }

    public function testTheEmptyStateOffersTheFormToo(): void
    {
        $html = $this->renderServicesPage(true, [
            'id' => 7,
            'name' => 'খালি ক্যাটাগরি',
            'description' => '',
            'icon' => 'layers',
        ], []);

        assertSame(['/admin/services?category=7'], $this->linksLabelled($html, self::EMPTY_CTA));
        assertSame(['/admin/services?category=7'], $this->linksLabelled($html, self::CTA));
    }

    public function testRegularUserSeesNoCreateShortcutAnywhere(): void
    {
        $html = $this->renderServicesPage(false, [
            'id' => 7,
            'name' => 'মোবাইল রিচার্জ',
            'description' => 'যেকোনো অপারেটর',
            'icon' => 'smartphone',
        ], [['id' => 1, 'name' => 'ডেমো', 'slug' => 'demo', 'price' => 10, 'category_id' => 7, 'accent' => 'blue']]);

        assertStringNotContainsString(self::CTA, $html);
        assertStringNotContainsString(self::EMPTY_CTA, $html);
        // Nothing anywhere on the page — neither the page body nor the chrome.
        assertStringNotContainsString('/admin/services', $html);
    }

    public function testTheSidebarOnlyListsServiceManagementForAdmins(): void
    {
        $twig = $this->environment();
        $context = fn (bool $admin): array => [
            'identity' => $this->identity($admin),
            'csrf' => 'token',
            'unread' => 0,
            'currentPath' => '/services',
            'seoUrl' => 'https://example.test',
        ];

        assertStringContainsString('সার্ভিস ম্যানেজমেন্ট', $twig->render('partials/sidebar.twig', $context(true)));
        assertStringNotContainsString('সার্ভিস ম্যানেজমেন্ট', $twig->render('partials/sidebar.twig', $context(false)));
    }

    public function testAKnownCategoryIsPreselected(): void
    {
        $categories = [['id' => 1, 'name' => 'মোবাইল'], ['id' => 7, 'name' => 'মোবাইল রিচার্জ']];

        assertSame(
            ['category_id' => 7],
            AdminServicesAction::preselectCategoryValues(7, $categories),
        );
    }

    public function testAnUnknownCategoryFallsBackToTheSelectDefault(): void
    {
        $categories = [['id' => 1, 'name' => 'মোবাইল']];

        // Null, not ['category_id' => 99]: a selected-but-missing option would be
        // submitted back as a category id the database does not have.
        assertNull(AdminServicesAction::preselectCategoryValues(99, $categories));
        assertNull(AdminServicesAction::preselectCategoryValues(0, $categories));
        assertNull(AdminServicesAction::preselectCategoryValues(-3, $categories));
        assertNull(AdminServicesAction::preselectCategoryValues(7, []));
    }
}
