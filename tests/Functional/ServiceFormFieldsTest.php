<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\ServiceManager;
use App\ServiceProvider\ServiceField;
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
    private ConnectionInterface $db;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->manager = new ServiceManager(
            new ServiceRepository($this->db),
            new TransactionRepository($this->db),
            new UserRepository($this->db),
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            new \App\Notification\NotificationManager(
                new NotificationRepository($this->db),
                new \App\Notification\QueueRepository($this->db),
                new \App\Notification\TemplateRenderer(
                    $this->db,
                    new \App\Repository\SettingsRepository($this->db),
                ),
                new UserRepository($this->db),
                $this->db,
            ),
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

    public function testLabelledCustomFieldIsRenderedAlongsideProviderFields(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => 'nid_number', 'required' => true],
                [
                    'name' => 'mobile_number',
                    'label' => 'মোবাইল নম্বর',
                    'type' => 'tel',
                    'required' => false,
                    'placeholder' => '01XXXXXXXXX',
                    'help' => 'যেখানে ফলাফল পাঠানো হবে',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $fields = $this->manager->fieldsFor($service);
        assertSame(['nid_number', 'mobile_number'], array_map(static fn ($f): string => $f->name, $fields));
        assertSame('মোবাইল নম্বর', $fields[1]->label);
        assertSame('tel', $fields[1]->type);
        assertSame('01XXXXXXXXX', $fields[1]->placeholder);
        assertSame('যেখানে ফলাফল পাঠানো হবে', $fields[1]->help);
        assertFalse($fields[1]->required);

        $customs = $this->manager->customFieldsFor($service);
        assertCount(1, $customs);
        assertSame('mobile_number', $customs[0]->name);
    }

    public function testCustomFieldWithoutALabelIsDropped(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => 'nid_number', 'required' => true],
                ['name' => 'mobile_number', 'label' => '   '],
            ], JSON_THROW_ON_ERROR),
        ]);

        assertSame(['nid_number'], array_map(static fn ($f): string => $f->name, $this->manager->fieldsFor($service)));
        assertSame([], $this->manager->customFieldsFor($service));
    }

    public function testCustomFieldWithAnUnusableNameIsDropped(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => '9lives', 'label' => 'নাম'],
                ['name' => 'has space', 'label' => 'নাম'],
            ], JSON_THROW_ON_ERROR),
        ]);

        assertSame([], $this->manager->customFieldsFor($service));
    }

    public function testCustomFieldWithAReservedNameIsDropped(): void
    {
        // `do` and `id` are read from the request body by the handlers, so a
        // field carrying one of those names would hijack their dispatch keys.
        foreach (ServiceField::RESERVED_NAMES as $reserved) {
            $service = $this->nidService([
                'form_fields' => json_encode([
                    ['name' => $reserved, 'label' => 'ছাঁদ'],
                ], JSON_THROW_ON_ERROR),
            ]);

            assertSame([], $this->manager->customFieldsFor($service), $reserved);
            assertFalse(ServiceField::isValidName($reserved));
        }

        assertTrue(ServiceField::isValidName('mobile_number'));
    }

    public function testCustomFieldWithAnUnknownTypeFallsBackToText(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => 'memo', 'label' => 'মেমো', 'type' => '<script>'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $fields = $this->manager->fieldsFor($service);
        assertCount(1, $fields);
        assertSame('text', $fields[0]->type);
    }

    public function testDisabledCustomFieldIsHiddenButSurvivesTheConfig(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => 'nid_number', 'required' => true],
                ['name' => 'mobile_number', 'label' => 'মোবাইল', 'type' => 'tel', 'enabled' => false],
            ], JSON_THROW_ON_ERROR),
        ]);

        assertSame(['nid_number'], array_map(static fn ($f): string => $f->name, $this->manager->fieldsFor($service)));

        $config = $this->manager->formFieldConfig($service);
        assertCount(2, $config);
        assertSame('mobile_number', $config[1]['name']);
        assertFalse($config[1]['enabled']);
        assertSame('মোবাইল', $config[1]['label']);

        // Still editable, so the admin can switch it back on.
        assertCount(1, $this->manager->customFieldsFor($service));
    }

    public function testCustomFieldWithoutEnabledFlagStaysVisible(): void
    {
        $service = $this->nidService([
            'form_fields' => json_encode([
                ['name' => 'mobile_number', 'label' => 'মোবাইল'],
            ], JSON_THROW_ON_ERROR),
        ]);

        assertCount(1, $this->manager->fieldsFor($service));
        assertTrue($this->manager->formFieldConfig($service)[0]['enabled']);
    }
}
