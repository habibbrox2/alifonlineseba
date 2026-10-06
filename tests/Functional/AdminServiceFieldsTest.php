<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ServiceProvider\ServiceField;
use App\Web\Admin\AdminServicesAction;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The add / edit / remove cycle of the admin "form field configuration" form.
 *
 * These exercise the pure builders on the action directly: what gets stored
 * depends only on what the admin posted plus what the service already had, so
 * none of it needs a request, a session or the database.
 */
final class AdminServiceFieldsTest extends \Codeception\Test\Unit
{
    /**
     * @return ServiceField[]
     */
    private function providerFields(): array
    {
        return [
            new ServiceField('nid_number', 'জাতীয় পরিচয়পত্র', 'text', true, '১০ বা ১৩ ডিজিট'),
            new ServiceField('date_of_birth', 'জন্ম তারিখ', 'date', true),
        ];
    }

    /**
     * @return ServiceField[]
     */
    private function customFields(): array
    {
        return [new ServiceField('mobile_number', 'মোবাইল নম্বর', 'tel', true, '01XXXXXXXXX')];
    }

    private function names(array $config): array
    {
        return array_column($config, 'name');
    }

    public function testAddingAFieldStoresItEnabled(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'required_fields' => ['nid_number'],
            'custom_new' => [0 => [
                'name' => 'mobile_number',
                'label' => 'মোবাইল নম্বর',
                'type' => 'tel',
                'required' => 'on',
                'placeholder' => '01XXXXXXXXX',
                'help' => 'ফলাফল এখানে পাঠানো হবে',
            ]],
        ], $this->providerFields(), []);

        assertSame([], $result['errors']);
        assertSame(['mobile_number'], $result['added']);
        assertSame(['nid_number', 'mobile_number'], $this->names($result['config']));

        $custom = $result['config'][1];
        assertSame('মোবাইল নম্বর', $custom['label']);
        assertSame('tel', $custom['type']);
        assertSame('01XXXXXXXXX', $custom['placeholder']);
        assertTrue($custom['required']);
        assertTrue($custom['enabled']);
    }

    public function testNewFieldIsOptionalWhenNotMarkedRequired(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'required_fields' => ['nid_number'],
            'custom_new' => [0 => ['name' => 'memo', 'label' => 'মেমো']],
        ], $this->providerFields(), []);

        assertSame([], $result['errors']);
        assertFalse($result['config'][1]['required']);
        assertTrue($result['config'][1]['enabled']);
    }

    public function testEditAndRemoveInOneSubmission(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'required_fields' => ['nid_number'],
            'remove_custom' => ['old_field'],
            'custom_label' => ['mobile_number' => 'মোবাইল (নতুন)'],
            'custom_type' => ['mobile_number' => 'number'],
            'custom_placeholder' => ['mobile_number' => '01XXXXXXXXX'],
            'custom_help' => ['mobile_number' => ''],
            'custom_new' => [0 => ['name' => 'email', 'label' => 'ইমেইল', 'type' => 'email']],
        ], $this->providerFields(), [
            $this->customFields()[0],
            new ServiceField('old_field', 'পুরনো ফিল্ড'),
        ]);

        assertSame([], $result['errors']);
        assertSame(['email'], $result['added']);
        assertSame(['old_field'], $result['removed']);
        assertSame(['nid_number', 'mobile_number', 'email'], $this->names($result['config']));

        $edited = $result['config'][1];
        assertSame('মোবাইল (নতুন)', $edited['label']);
        assertSame('number', $edited['type']);
        assertSame('', $edited['help']);
    }

    public function testDisabledFieldKeepsItsDefinition(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'required_fields' => ['nid_number'],
            'custom_label' => ['mobile_number' => 'মোবাইল নম্বর'],
        ], $this->providerFields(), $this->customFields());

        assertSame([], $result['errors']);
        $custom = $result['config'][1];
        assertFalse($custom['enabled']);
        assertFalse($custom['required']);
        assertSame('মোবাইল নম্বর', $custom['label']);
        assertSame('01XXXXXXXXX', $custom['placeholder']);
    }

    public function testBlankLabelOnAnExistingFieldIsRejected(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'custom_label' => ['mobile_number' => '  '],
        ], $this->providerFields(), $this->customFields());

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
    }

    public function testUnknownRemoveTargetIsRejected(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'remove_custom' => ['nid_number'],
        ], $this->providerFields(), $this->customFields());

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
    }

    public function testNewFieldCollidingWithAProviderFieldIsRejected(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'custom_new' => [0 => ['name' => 'nid_number', 'label' => 'ডুপ্লিকেট']],
        ], $this->providerFields(), []);

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
        assertSame([], $result['added']);
    }

    public function testDuplicateCustomFieldIsRejected(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'custom_new' => [0 => ['name' => 'mobile_number', 'label' => 'আরেকটি']],
        ], $this->providerFields(), $this->customFields());

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
    }

    public function testRequiredButDisabledFieldIsRejected(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'required_fields' => ['nid_number', 'mobile_number'],
        ], $this->providerFields(), $this->customFields());

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
    }

    public function testUnknownFieldNameInACheckboxIsRejected(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number', 'ghost'],
        ], $this->providerFields(), []);

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
    }

    public function testUntouchedBlankNewRowIsIgnored(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number'],
            'required_fields' => ['nid_number'],
            'custom_new' => [0 => ['name' => '', 'label' => '', 'type' => 'text']],
        ], $this->providerFields(), []);

        assertSame([], $result['errors']);
        assertSame(['nid_number'], $this->names($result['config']));
    }

    public function testNewFieldWithABadNameOrNoLabelIsRejected(): void
    {
        $badName = AdminServicesAction::buildFormFieldConfig([
            'custom_new' => [0 => ['name' => 'মোবাইল', 'label' => 'মোবাইল']],
        ], $this->providerFields(), []);
        assertNotEmpty($badName['errors']);

        $noLabel = AdminServicesAction::buildFormFieldConfig([
            'custom_new' => [0 => ['name' => 'memo', 'label' => '']],
        ], $this->providerFields(), []);
        assertNotEmpty($noLabel['errors']);
    }

    public function testNewFieldWithAnUnknownTypeFallsBackToText(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'custom_new' => [0 => ['name' => 'memo', 'label' => 'মেমো', 'type' => 'color-picker']],
        ], $this->providerFields(), []);

        assertSame([], $result['errors']);
        assertSame('text', $result['config'][0]['type']);
    }

    public function testUncheckingEverythingKeepsTheCustomDefinitionButSwitchesItOff(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([], $this->providerFields(), $this->customFields());

        assertSame([], $result['errors']);
        assertSame(['mobile_number'], $this->names($result['config']));
        assertFalse($result['config'][0]['enabled']);
        assertSame('মোবাইল নম্বর', $result['config'][0]['label']);
    }

    public function testNonScalarTextInputCollapsesToEmpty(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'custom_new' => [0 => [
                'name' => 'memo',
                'label' => 'মেমো',
                'placeholder' => ['nested'],
                'help' => ['nested'],
            ]],
        ], $this->providerFields(), []);

        assertSame([], $result['errors']);
        assertSame('মেমো', $result['config'][0]['label']);
        assertSame('', $result['config'][0]['placeholder']);
        assertSame('', $result['config'][0]['help']);
    }

    public function testNonScalarLabelOnANewFieldIsReportedAsMissing(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'custom_new' => [0 => ['name' => 'memo', 'label' => ['nested']]],
        ], $this->providerFields(), []);

        assertNotEmpty($result['errors']);
        assertSame([], $result['config']);
    }

    public function testFieldOptionsUseDefaultsWhenNothingIsStored(): void
    {
        $options = AdminServicesAction::buildFieldOptions($this->providerFields(), null);

        assertCount(2, $options);
        assertSame('nid_number', $options[0]['name']);
        assertTrue($options[0]['enabled']);
        assertTrue($options[0]['required']);
        assertFalse($options[0]['custom']);
    }

    public function testFieldOptionsFollowTheStoredOrderAndAppendWhateverItDoesNotPlace(): void
    {
        // This used to promise that provider fields always come first and custom
        // ones last. It cannot still: an admin who drags a custom field above a
        // provider one has to see it stay there next time, or the page undoes
        // their work on every visit. The stored order now wins, and a provider
        // field the configuration never mentions — `nid_number` below — lands at
        // the end, because it has no position of its own to keep.
        $options = AdminServicesAction::buildFieldOptions($this->providerFields(), [
            ['name' => 'mobile_number', 'label' => 'মোবাইল', 'type' => 'tel', 'required' => true, 'enabled' => true],
            ['name' => 'date_of_birth', 'required' => false],
        ]);

        assertSame(['mobile_number', 'date_of_birth', 'nid_number'], $this->names($options));
        assertTrue($options[1]['enabled'], 'Being in the configuration is what switches a provider field on.');
        assertFalse($options[1]['required']);
        assertFalse($options[1]['custom']);
        assertTrue($options[0]['custom']);
        assertTrue($options[0]['enabled']);
        assertSame('', $options[0]['placeholder']);
        assertFalse($options[2]['enabled'], 'A provider field absent from the config is off, as before.');
    }

    public function testDisabledFieldOptionIsNeverRequired(): void
    {
        $options = AdminServicesAction::buildFieldOptions([], [
            ['name' => 'mobile_number', 'label' => 'মোবাইল', 'type' => 'tel', 'required' => true, 'enabled' => false],
        ]);

        assertCount(1, $options);
        assertFalse($options[0]['enabled']);
        assertFalse($options[0]['required']);
        assertTrue($options[0]['custom']);
    }

    public function testConfigWithoutAnEnabledFlagShowsTheCustomField(): void
    {
        $options = AdminServicesAction::buildFieldOptions([], [
            ['name' => 'mobile_number', 'label' => 'মোবাইল', 'type' => 'tel', 'required' => true],
        ]);

        assertTrue($options[0]['enabled']);
        assertTrue($options[0]['required']);
    }

    // ---- Dragging rows into an order ---------------------------------------

    public function testTheOrderTheRowsAreDraggedIntoIsTheOrderThatIsStored(): void
    {
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number', 'date_of_birth'],
            'field_order' => ['date_of_birth', 'nid_number', 'mobile_number'],
            'custom_label' => ['mobile_number' => 'মোবাইল নম্বর'],
        ], $this->providerFields(), $this->customFields());

        assertSame([], $result['errors']);
        assertSame(
            ['date_of_birth', 'nid_number', 'mobile_number'],
            $this->names($result['config']),
            'A custom field dragged above a provider one keeps its place — otherwise the ordering the '
            . 'admin just did would be thrown away for half the fields.',
        );
    }

    public function testAFieldMissingFromThePostedOrderKeepsItsPlaceRatherThanVanishing(): void
    {
        // A provider that gains a field after this page was rendered, a browser
        // that reloaded the form from a stale copy, a script that half-ran.
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number', 'date_of_birth'],
            'field_order' => ['date_of_birth'],
        ], $this->providerFields(), $this->customFields());

        assertSame([], $result['errors']);
        assertSame(
            ['date_of_birth', 'nid_number', 'mobile_number'],
            $this->names($result['config']),
            'Named rows first in the order given, then everything else in the order it would have had.',
        );
    }

    public function testAnUnknownNameInTheOrderIsIgnoredRatherThanLosingTheEdits(): void
    {
        // The deliberate difference from `fields[]`, where an unknown name is
        // refused: an ordering hint cannot change what the form contains, so a
        // stale one should cost the admin nothing.
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['nid_number', 'date_of_birth'],
            'field_order' => ['ghost', 'date_of_birth', 'nid_number', 'ghost'],
            'custom_label' => ['mobile_number' => 'মোবাইল (নতুন)'],
        ], $this->providerFields(), $this->customFields());

        assertSame([], $result['errors']);
        assertSame(['date_of_birth', 'nid_number', 'mobile_number'], $this->names($result['config']));
        assertSame('মোবাইল (নতুন)', $result['config'][2]['label']);
    }

    public function testAFormWithNoOrderListStillSavesInTheOrderItWasPostedIn(): void
    {
        // JavaScript off, or an old tab: the rows are still in the server's order,
        // and that is the order that must be kept.
        $result = AdminServicesAction::buildFormFieldConfig([
            'fields' => ['date_of_birth', 'nid_number'],
        ], $this->providerFields(), []);

        assertSame(['date_of_birth', 'nid_number'], $this->names($result['config']));
    }

    public function testReorderingDoesNotChangeWhatTheFormContains(): void
    {
        $fields = ['fields' => ['nid_number', 'date_of_birth'], 'required_fields' => ['date_of_birth']];

        $before = AdminServicesAction::buildFormFieldConfig($fields, $this->providerFields(), []);
        $after = AdminServicesAction::buildFormFieldConfig(
            $fields + ['field_order' => ['date_of_birth', 'nid_number']],
            $this->providerFields(),
            [],
        );

        $sorted = static function (array $config): array {
            usort($config, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

            return $config;
        };

        assertSame(
            ['nid_number', 'date_of_birth'],
            $this->names($before['config']),
        );
        assertSame(
            ['date_of_birth', 'nid_number'],
            $this->names($after['config']),
            'The drag is what moved them, and nothing else.',
        );
        assertSame(
            $sorted($before['config']),
            $sorted($after['config']),
            'Same fields with the same flags — a drag is not allowed to quietly change a required '
            . 'checkbox on its way past.',
        );
    }
}
