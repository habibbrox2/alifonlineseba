<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceProvider\MockLocalGovService;
use App\ServiceProvider\ServiceField;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The pourashava/union application provider: the identity facts every local
 * government order needs, that nothing is ever filed from here, and the
 * NID/birth-registration and mobile shapes its execute() contract enforces.
 */
final class MockLocalGovServiceTest extends \Codeception\Test\Unit
{
    private MockLocalGovService $provider;

    protected function _before(): void
    {
        $this->provider = new MockLocalGovService();
    }

    public function testKeyAndNameIdentifyTheProvider(): void
    {
        assertSame('mock-local-gov', $this->provider->key());
        assertSame('Local Government Application (Demo)', $this->provider->name());
    }

    public function testFieldsCollectTheApplicantIdentityAndTheDetails(): void
    {
        $names = array_map(static fn (ServiceField $f): string => $f->name, $this->provider->fields());

        assertEquals(['applicant_name', 'nid_number', 'contact_mobile', 'details'], $names);

        $byName = [];
        foreach ($this->provider->fields() as $field) {
            $byName[$field->name] = $field;
        }
        assertTrue($byName['applicant_name']->required);
        assertTrue($byName['nid_number']->required);
        assertTrue($byName['contact_mobile']->required);
        assertFalse($byName['details']->required);
        assertSame('tel', $byName['contact_mobile']->type);
        assertSame('textarea', $byName['details']->type);
    }

    public function testItNeverGeneratesAResultOnItsOwn(): void
    {
        assertFalse($this->provider->autoGenerate());
    }

    public function testValidInputReturnsADemoPayload(): void
    {
        $result = $this->provider->execute([
            'applicant_name' => '  মোঃ রহিম উদ্দিন ',
            'nid_number' => '1990-123456789',
            'contact_mobile' => '01712-345678',
            'details' => 'রহিম ট্রেডার্স — খুচরা বিক্রয়',
        ]);

        assertTrue($result->success);
        assertSame([], $result->errors);
        assertTrue((bool) ($result->data['_demo'] ?? false));
        assertSame('মোঃ রহিম উদ্দিন', $result->data['applicant_name']);
        assertSame('1990123456789', $result->data['nid_number']);
        assertSame('01712345678', $result->data['contact_mobile']);
        assertSame('রহিম ট্রেডার্স — খুচরা বিক্রয়', $result->data['details']);
    }

    public function testABirthRegistrationNumberIsAccepted(): void
    {
        $result = $this->provider->execute(['nid_number' => '19761234567801234']);

        assertTrue($result->success);
        assertSame('19761234567801234', $result->data['nid_number']);
    }

    public function testANineDigitIdentityNumberIsRefused(): void
    {
        $result = $this->provider->execute(['nid_number' => '123456789']);

        assertFalse($result->success);
        assertSame(['nid_number'], array_keys($result->errors));
        assertSame([], $result->data);
    }

    public function testMalformedMobileIsRefused(): void
    {
        $result = $this->provider->execute([
            'applicant_name' => 'করিম মিয়া',
            'nid_number' => '1987543210987',
            'contact_mobile' => '12345',
        ]);

        assertFalse($result->success);
        assertSame(['contact_mobile'], array_keys($result->errors));
    }
}
