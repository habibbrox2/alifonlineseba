<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceProvider\MockTeletalkPaymentService;
use App\ServiceProvider\ServiceField;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The Teletalk payment provider: the order form it exposes, that it never
 * generates a result on its own (the two SMS go out from the operator's own
 * SIM), and the demo payload / validation its execute() contract carries.
 */
final class MockTeletalkPaymentServiceTest extends \Codeception\Test\Unit
{
    private MockTeletalkPaymentService $provider;

    protected function _before(): void
    {
        $this->provider = new MockTeletalkPaymentService();
    }

    public function testKeyAndNameIdentifyTheProvider(): void
    {
        assertSame('mock-teletalk-payment', $this->provider->key());
        assertSame('Teletalk Payment (Demo)', $this->provider->name());
    }

    public function testFieldsCollectTheUserMobileAndPost(): void
    {
        $names = array_map(static fn (ServiceField $f): string => $f->name, $this->provider->fields());

        assertEquals(['user_id', 'contact_mobile', 'post_name'], $names);

        $byName = [];
        foreach ($this->provider->fields() as $field) {
            $byName[$field->name] = $field;
        }
        assertTrue($byName['user_id']->required);
        assertTrue($byName['contact_mobile']->required);
        assertFalse($byName['post_name']->required);
        assertSame('tel', $byName['contact_mobile']->type);
    }

    public function testItNeverGeneratesAResultOnItsOwn(): void
    {
        assertFalse($this->provider->autoGenerate());
    }

    public function testValidInputReturnsADemoPayloadThatNamesBothSmsSteps(): void
    {
        $result = $this->provider->execute([
            'user_id' => ' mzdtp2xb ',
            'contact_mobile' => '01318-129911',
            'post_name' => 'ব্যাংক কেসার',
        ]);

        assertTrue($result->success);
        assertSame([], $result->errors);
        assertTrue((bool) ($result->data['_demo'] ?? false));
        assertSame('MZDTP2XB', $result->data['user_id']);
        assertSame('01318129911', $result->data['contact_mobile']);
        assertSame('DLS MZDTP2XB → ১৬২২২', $result->data['sms_1']);
        assertSame('DLS YES <PIN> → ১৬২২২', $result->data['sms_2']);
    }

    public function testMalformedUserIdIsRefused(): void
    {
        $result = $this->provider->execute(['user_id' => 'ab']);

        assertFalse($result->success);
        assertSame(['user_id'], array_keys($result->errors));
        assertSame([], $result->data);
    }

    public function testMalformedMobileIsRefused(): void
    {
        $result = $this->provider->execute([
            'user_id' => 'MZDTP2XB',
            'contact_mobile' => '12345',
        ]);

        assertFalse($result->success);
        assertSame(['contact_mobile'], array_keys($result->errors));
    }
}
