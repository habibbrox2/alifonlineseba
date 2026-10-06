<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\UserRepository;
use App\Service\ServiceDate;
use App\Service\UserFields;
use App\Twig\TwigExtension;
use Codeception\Test\Unit;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Exception\NotFoundException;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * A birth date on the account: what the field shows, and what the row keeps.
 *
 * Both halves are checked here on purpose. The view half renders the real
 * template through a real Twig environment, because the promise this feature
 * makes is a promise about *what a person sees* — a test that only asserted on
 * the repository would still pass after someone replaced the widget with a
 * bare `<input type="date">`. The database half is what stops the display
 * format from leaking into storage, where it would quietly sort wrong.
 */
final class UserBirthDateTest extends Unit
{
    private ConnectionInterface $db;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
    }

    /** Stands in for App\Auth\Identity, which needs a session-backed row. */
    private function identity(): object
    {
        return new class () {
            public function canAccessAdmin(): bool
            {
                return true;
            }

            public function __get(string $name): mixed
            {
                return match ($name) {
                    'id' => 1,
                    'username' => 'admin01',
                    'phone' => '01700000000',
                    'fullName' => 'সুপার অ্যাডমিন',
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
    private function twig(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/resources/views'),
            ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false],
        );
        $twig->addExtension(new TwigExtension());

        return $twig;
    }

    public function testTheDateFieldIsTheSameMarkupWhicheverFormAsksForIt(): void
    {
        // One file, `components/date-field.twig`, renders this — so comparing
        // two forms renders the same string twice. The assertion that matters
        // is the one below it: the shape itself.
        $html = $this->twig()->render('components/date-field.twig', [
            'id' => 'date_of_birth',
            'name' => 'date_of_birth',
            'label' => 'জন্ম তারিখ (ঐচ্ছিক)',
            'value' => '06-10-2026',
            'required' => false,
        ]);

        // A text field with a numeric keypad, not a native date input: the
        // browser's own date widget renders in the OS's order, which is the
        // ambiguity this whole feature exists to remove.
        assertStringContainsString('type="text"', $html);
        assertStringContainsString('inputmode="numeric"', $html);
        assertStringContainsString('pattern="[0-9]{2}-[0-9]{2}-[0-9]{4}"', $html);
        assertStringContainsString('data-date-field', $html);
        assertStringContainsString('data-date-input', $html);

        // The hint reads the format from ServiceDate rather than repeating it.
        assertStringContainsString('ফরম্যাট: DD-MM-YYYY', $html);
        assertStringContainsString('value="06-10-2026"', $html);
    }

    public function testTheEditorShowsTheDisplayFormOfAStoredDate(): void
    {
        $html = $this->twig()->render('site/admin/user-edit.twig', [
            'row' => [
                'id' => 7,
                'full_name' => 'রহিম উদ্দিন',
                'username' => 'rahim',
                'phone' => '01712345678',
                'email' => 'rahim@example.test',
                'role' => 'user',
                'status' => 'active',
                'balance' => '10.00',
                'created_at' => '2026-01-02 10:00:00',
                'last_login_at' => null,
                'deleted_at' => null,
                // The column holds this…
                'date_of_birth' => '2026-10-06',
            ],
            'errors' => [],
            // …and the action converts it before the template ever sees it.
            'birthDateDisplay' => ServiceDate::display('2026-10-06'),
            'csrf' => 'token',
            'identity' => $this->identity(),
            'unread' => 0,
            'currentPath' => '/admin/users/7',
            'siteUrl' => 'https://example.test',
        ]);

        assertStringContainsString('name="date_of_birth"', $html);
        // The ISO form must not reach the input: that is the whole leak this
        // change set exists to close.
        assertStringContainsString('value="06-10-2026"', $html);
        assertStringNotContainsString('value="2026-10-06"', $html);
    }

    public function testARejectedDateComesBackTheWayItWasTyped(): void
    {
        // The action puts the raw text back into the row so the redisplayed
        // form shows the mistake rather than a reformatted version of it.
        $html = $this->twig()->render('components/date-field.twig', [
            'id' => 'date_of_birth',
            'name' => 'date_of_birth',
            'label' => 'জন্ম তারিখ (ঐচ্ছিক)',
            'value' => ServiceDate::display('31-02-1990'),
            'required' => false,
            'error' => ServiceDate::errorMessage(),
        ]);

        assertStringContainsString('value="31-02-1990"', $html);
        assertStringContainsString(ServiceDate::errorMessage(), $html);
    }

    public function testATypedDateSurvivesTheRoundTripToTheColumn(): void
    {
        $users = new UserRepository($this->db);
        $stamp = (string) random_int(100000, 999999);

        $checked = UserFields::validate([
            'full_name' => 'বার্তা যাচাই',
            'username' => 'dob_' . $stamp,
            'phone' => '017' . $stamp,
            'email' => 'dob_' . $stamp . '@example.test',
            'date_of_birth' => '29-02-2024',
        ]);

        $id = $users->create([...$checked['values'], 'password_hash' => 'x']);

        try {
            $row = $users->findById($id);
            assertNotNull($row);
            assertSame('2024-02-29', (string) $row['date_of_birth']);
        } finally {
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
    }

    public function testAnAccountWithNoBirthDateStoresNull(): void
    {
        $users = new UserRepository($this->db);
        $stamp = (string) random_int(100000, 999999);

        $id = $users->create([
            'full_name' => 'তারিখ নেই',
            'username' => 'nodob_' . $stamp,
            'phone' => '018' . $stamp,
            'password_hash' => 'x',
            'date_of_birth' => null,
        ]);

        try {
            $row = $users->findById($id);
            assertNotNull($row);
            // NULL, not '0000-00-00': an absent answer has to stay
            // distinguishable from a real one, and a zero date is not a date.
            assertNull($row['date_of_birth']);
        } finally {
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
    }
}