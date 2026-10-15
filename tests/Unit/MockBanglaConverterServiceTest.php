<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceProvider\MockBanglaConverterService;
use App\ServiceProvider\ServiceField;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The Bangla text converter provider: the two fields that describe one
 * conversion, that no conversion runs here (there is no glyph table), and the
 * bounds its execute() contract enforces on the submitted text.
 */
final class MockBanglaConverterServiceTest extends \Codeception\Test\Unit
{
    private MockBanglaConverterService $provider;

    protected function _before(): void
    {
        $this->provider = new MockBanglaConverterService();
    }

    public function testKeyAndNameIdentifyTheProvider(): void
    {
        assertSame('mock-bangla-converter', $this->provider->key());
        assertSame('Bangla Text Converter (Demo)', $this->provider->name());
    }

    public function testFieldsCollectTheTextAndTheDirection(): void
    {
        $names = array_map(static fn (ServiceField $f): string => $f->name, $this->provider->fields());

        assertEquals(['source_text', 'direction'], $names);

        $byName = [];
        foreach ($this->provider->fields() as $field) {
            $byName[$field->name] = $field;
        }
        assertTrue($byName['source_text']->required);
        assertSame('textarea', $byName['source_text']->type);
        assertFalse($byName['direction']->required);
    }

    public function testItNeverGeneratesAResultOnItsOwn(): void
    {
        assertFalse($this->provider->autoGenerate());
    }

    public function testValidInputReturnsADemoPayloadAndDefaultsTheDirection(): void
    {
        $result = $this->provider->execute(['source_text' => 'আমার সোনার বাংলা']);

        assertTrue($result->success);
        assertTrue((bool) ($result->data['_demo'] ?? false));
        assertSame('Sutonny → Unicode', $result->data['direction']);
        assertSame(mb_strlen('আমার সোনার বাংলা'), $result->data['character_count']);
        assertSame('আমার সোনার বাংলা', $result->data['source_preview']);
    }

    public function testTheGivenDirectionIsKept(): void
    {
        $result = $this->provider->execute([
            'source_text' => 'gj-revbN brmy',
            'direction' => 'Sutonny → Unicode',
        ]);

        assertTrue($result->success);
        assertSame('Sutonny → Unicode', $result->data['direction']);
        assertSame('gj-revbN brmy', $result->data['source_preview']);
    }

    public function testEmptyTextIsRefused(): void
    {
        $result = $this->provider->execute(['source_text' => '   ']);

        assertFalse($result->success);
        assertSame(['source_text'], array_keys($result->errors));
        assertSame([], $result->data);
    }

    public function testTextOverTheCharacterCeilingIsRefused(): void
    {
        $result = $this->provider->execute(['source_text' => str_repeat('ক', 5001)]);

        assertFalse($result->success);
        assertSame(['source_text'], array_keys($result->errors));
    }
}
