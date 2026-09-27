<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\ServiceManager;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Exception\NotFoundException;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Form field configuration of a service: admin-configured fields narrow the
 * provider defaults, unknown names are dropped and NULL config means defaults.
 */
final class ServiceFormFieldsTest extends \Codeception\Test\Unit
{
    private ServiceManager $manager;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $db = $container->get(ConnectionInterface::class);
        $this->manager = new ServiceManager(
            new ServiceRepository($db),
            new TransactionRepository($db),
            new UserRepository($db),
            new ActivityLogRepository($db),
            new NotificationRepository($db),
        );
    }

    private function nidService(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'slug' => 'nid-check',
            'service_type' => 'mock',
            'form_fields' => null,
        ], $overrides);
    }

    public function testProviderDefaultsApplyWithoutConfig(): void
    {
        $fields = $this->manager->fieldsFor($this->nidService());

        $names = array_map(static fn ($field): string => $field->name, $fields);
        assertSame(['nid_number', 'date_of_birth'], $names);
        assertTrue($fields[0]->required);
        assertTrue($fields[1]->required);
        assertNull($this->manager->formFieldConfig($this->nidService()));
    }

    public function testConfigSelectsAndUnflagsFields(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => 'nid_number', 'required' => false],
                ['name' => 'date_of_birth', 'required' => true],
            ], JSON_THROW_ON_ERROR),
        ]);

        $fields = $this->manager->fieldsFor($service);
        assertCount(2, $fields);
        assertSame('nid_number', $fields[0]->name);
        assertFalse($fields[0]->required);
        assertSame('date_of_birth', $fields[1]->name);
        assertTrue($fields[1]->required);
    }

    public function testDisabledFieldsAreHiddenFromTheForm(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([['name' => 'nid_number', 'required' => true]], JSON_THROW_ON_ERROR),
        ]);

        $fields = $this->manager->fieldsFor($service);
        assertCount(1, $fields);
        assertSame('nid_number', $fields[0]->name);
    }

    public function testEmptyConfigMeansNoInputFields(): void
    {
        assertSame([], $this->manager->fieldsFor($this->nidService(['form_fields' => '[]'])));
    }

    public function testUnknownAndBrokenConfigFallsBackToDefaults(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([['name' => 'nope', 'required' => true]], JSON_THROW_ON_ERROR),
        ]);
        assertSame([], $this->manager->fieldsFor($service));
        assertSame([], $this->manager->formFieldConfig($service));

        $broken = $this->nidService(['form_fields' => 'not-json']);
        $names = array_map(static fn ($field): string => $field->name, $this->manager->fieldsFor($broken));
        assertSame(['nid_number', 'date_of_birth'], $names);
    }

    public function testConfigAcceptsAlreadyDecodedArray(): void
    {
        $service = $this->nidService([
            'form_fields' => [['name' => 'date_of_birth', 'required' => false]],
        ]);

        $fields = $this->manager->fieldsFor($service);
        assertCount(1, $fields);
        assertFalse($fields[0]->required);
    }
}
