<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\Identity;
use App\Twig\TwigExtension;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The two role screens that render the permission/hierarchy story.
 *
 * These templates shipped with a template that called an unknown `path()`
 * function — Twig has no such function in this app, so the page was a 500 on
 * every request — and with Bengali text that had been transliterated to
 * Cyrillic and ASCII lookalikes ("নোтаত", "পরlerle"), which rendered as
 * garbage. Both failures survive a `php -l`: one is a template compile error
 * and the other is not an error at all, so a test that actually renders the
 * pages is the only thing that catches them.
 *
 * The permission check is stubbed rather than taken from the container on
 * purpose: PermissionService resolves through RoleRankRepository, which needs a
 * database, and a unit test must run without one. The check itself is covered
 * by AdminMiddlewareTest and PermissionServiceTest.
 */
final class AdminRoleScreensTest extends \Codeception\Test\Unit
{
    private Environment $twig;

    protected function _before(): void
    {
        $this->twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/resources/views'),
            ['cache' => false, 'auto_reload' => true, 'autoescape' => 'html', 'strict_variables' => false],
        );
        $this->twig->addExtension(new TwigExtension());
        $this->twig->addExtension(new StubPermissions());
    }

    public function testPermissionsMatrixRenders(): void
    {
        // The exact shape AdminPermissionsMatrixAction::buildMatrix() passes:
        // category => action => bool, one level per role.
        $matrix = [
            'superadmin' => ['users' => ['create' => true, 'read' => true, 'manage_roles' => true]],
            'admin' => ['users' => ['create' => true, 'read' => true, 'manage_roles' => true]],
            'staff' => ['users' => ['create' => false, 'read' => true, 'manage_roles' => false]],
        ];

        $html = $this->render('site/admin/permissions-matrix.twig', [
            'identity' => $this->superAdmin(),
            'roles' => ['superadmin', 'admin', 'staff'],
            'matrix' => $matrix,
            'categoryLabels' => ['users' => 'ব্যবহারকারী ব্যবস্থাপনা'],
            'actionLabels' => ['create' => 'তৈরি', 'read' => 'দেখা', 'manage_roles' => 'ভূমিকা বরাদ্দ'],
            'roleRanks' => ['superadmin' => 100, 'admin' => 50, 'staff' => 25],
        ]);

        $this->assertStringContainsString('ব্যবহারকারী ব্যবস্থাপনা', $html);
        $this->assertStringContainsString('ভূমিকা বরাদ্দ', $html);
        $this->assertStringContainsString('সুপারএডমিন', $html);

        // The matrix must resolve real booleans: staff loses `create`, so a
        // "no permission" badge has to exist. A template that mis-iterated the
        // nested array would render a table of empty cells and no badges.
        $this->assertStringContainsString('badge badge-neutral', $html);
        $this->assertStringContainsString('badge badge-success', $html);
    }

    public function testRoleHierarchyRenders(): void
    {
        $html = $this->render('site/admin/role-hierarchy.twig', [
            'identity' => $this->superAdmin(),
            'ranks' => ['superadmin' => 100, 'admin' => 50, 'staff' => 25, 'user' => 10],
            'defaultRanks' => ['superadmin' => 100, 'admin' => 50, 'staff' => 25, 'user' => 10],
            'roleLabels' => ['superadmin' => 'সুপারএডমিন', 'admin' => 'এডমিন', 'staff' => 'স্টাফ', 'user' => 'ইউজার'],
        ]);

        $this->assertStringContainsString('বর্তমান ভূমিকা অর্ডার', $html);
        $this->assertStringContainsString('পদক্রম চিত্র', $html);
        $this->assertStringContainsString('নিয়মাবলী', $html);

        // Ranks persist to the role_rank table, so the page must not claim the
        // change is session-local.
        $this->assertStringNotContainsString('ব্রাউজার সেশনে সংরক্ষিত', $html);
        $this->assertStringContainsString('ডেটাবেজে সংরক্ষিত', $html);
    }

    public function testScreensHoldNoTransliteratedBengali(): void
    {
        foreach ([
            'resources/views/site/admin/permissions-matrix.twig',
            'resources/views/site/admin/role-hierarchy.twig',
        ] as $path) {
            $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $problems = [];

            foreach ($chars as $i => $ch) {
                $cp = mb_ord($ch, 'UTF-8');

                // Cyrillic or Greek standing in for a Bengali letter: "নোтаत".
                if (($cp >= 0x0370 && $cp <= 0x04FF)) {
                    $problems[] = sprintf('%s (U+%04X)', $ch, $cp);
                    continue;
                }

                // An ASCII letter wedged inside a Bengali word: "পরlerle".
                if (!($cp >= 0x41 && $cp <= 0x7A)) {
                    continue;
                }
                $isBengali = static fn (int $c): bool => $c >= 0x0980 && $c <= 0x09FF;
                $prev = $i > 0 ? mb_ord($chars[$i - 1], 'UTF-8') : 0;
                $next = $i + 1 < count($chars) ? mb_ord($chars[$i + 1], 'UTF-8') : 0;
                if ($isBengali($prev) || $isBengali($next)) {
                    $problems[] = sprintf('%s (U+%04X)', $ch, $cp);
                }
            }

            self::assertSame(
                [],
                $problems,
                "{$path} holds transliterated Bengali: " . implode(', ', $problems),
            );
        }
    }

    private function render(string $template, array $params): string
    {
        // Both pages extend the dashboard layout, which reads these on every
        // render; the layout is shared, so a test of the content block cannot
        // render in isolation without also satisfying the frame.
        return $this->twig->render($template, $params + [
            'currentPath' => '/admin',
            'csrf' => 'test-csrf',
            'unread' => 0,
        ]);
    }

    private function superAdmin(): Identity
    {
        return new Identity(
            id: 1,
            username: 'owner',
            phone: '01700000000',
            email: null,
            role: Identity::ROLE_SUPERADMIN,
            status: 'active',
            balance: 0.0,
            avatar: null,
            fullName: 'মালিক',
        );
    }
}

/** Grants everything, so the test exercises the render and not the authorisation. */
final class StubPermissions extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_access', static fn (Identity $i, string $r): bool => true),
            new TwigFunction('can', static fn (Identity $i, string $c, string $a, ?Identity $t = null): bool => true),
            new TwigFunction('can_manage', static fn (Identity $i, Identity $t): bool => true),
        ];
    }
}