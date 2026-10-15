<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceProvider\MockDigitalFileService;
use App\ServiceProvider\ServiceField;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The file/font download provider: the order form it exposes, that it never
 * generates a result on its own (there is no file library to serve from), and
 * the demo payload / validation its execute() contract carries.
 */
final class MockDigitalFileServiceTest extends \Codeception\Test\Unit
{
    private MockDigitalFileService $provider;

    protected function _before(): void
    {
        $this->provider = new MockDigitalFileService();
    }

    public function testKeyAndNameIdentifyTheProvider(): void
    {
        assertSame('mock-digital-file', $this->provider->key());
        assertSame('Digital File Download (Demo)', $this->provider->name());
    }

    public function testFieldsAskOnlyForTheFileAndAnOptionalMobile(): void
    {
        $names = array_map(static fn (ServiceField $f): string => $f->name, $this->provider->fields());

        assertEquals(['file_request', 'contact_mobile'], $names);

        $byName = [];
        foreach ($this->provider->fields() as $field) {
            $byName[$field->name] = $field;
        }
        assertFalse($byName['file_request']->required);
        assertFalse($byName['contact_mobile']->required);
        assertSame('tel', $byName['contact_mobile']->type);
    }

    public function testItNeverGeneratesAResultOnItsOwn(): void
    {
        assertFalse($this->provider->autoGenerate());
    }

    public function testValidInputReturnsADemoPayload(): void
    {
        $result = $this->provider->execute([
            'file_request' => ' বাংলা ফন্ট প্যাক ',
            'contact_mobile' => '01712-345678',
        ]);

        assertTrue($result->success);
        assertSame([], $result->errors);
        assertTrue((bool) ($result->data['_demo'] ?? false));
        assertSame('বাংলা ফন্ট প্যাক', $result->data['file_request']);
        assertSame('01712345678', $result->data['contact_mobile']);
    }

    public function testEverythingOptionalStillProducesAPayload(): void
    {
        $result = $this->provider->execute([]);

        assertTrue($result->success);
        assertSame('—', $result->data['file_request']);
        assertSame('—', $result->data['contact_mobile']);
    }

    public function testMalformedMobileIsRefused(): void
    {
        $result = $this->provider->execute(['contact_mobile' => '12345']);

        assertFalse($result->success);
        assertSame(['contact_mobile'], array_keys($result->errors));
        assertSame([], $result->data);
    }
}
