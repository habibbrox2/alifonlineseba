<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\SettingsRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Exception\NotFoundException;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertSame;

final class SettingsRepositoryTest extends \Codeception\Test\Unit
{
    private ?ConnectionInterface $db = null;
    private SettingsRepository $settings;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->settings = new SettingsRepository($this->db);
    }

    protected function _after(): void
    {
        // Clean any rows written by tests so defaults stay pristine.
        $this->db->createCommand()->delete('{{%site_setting}}', ['setting_key' => [
            'site_tagline', 'facebook_url', 'unknown_key',
        ]])->execute();
    }

    public function testAllReturnsDefaultsWithEmptyTable(): void
    {
        $values = $this->settings->all();

        assertSame('ইনস্ট্যান্ট ডিজিটাল সার্ভিস প্ল্যাটফর্ম', $values['site_tagline']);
        assertSame('', $values['facebook_url']);
        // Exactly the whitelisted keys — no unknown keys may leak through.
        assertSame(array_keys(SettingsRepository::KEYS), array_keys($values));
    }

    public function testPutManyWritesAndAllReadsBack(): void
    {
        $this->settings->putMany([
            'site_tagline' => '  টেস্ট ট্যাগলাইন  ',
            'facebook_url' => 'https://facebook.com/test',
        ], null);

        $values = $this->settings->all();
        assertSame('টেস্ট ট্যাগলাইন', $values['site_tagline'], 'Values must be trimmed.');
        assertSame('https://facebook.com/test', $values['facebook_url']);
    }

    public function testPutManyDropsUnknownKeysAndUnsafeUrls(): void
    {
        $countBefore = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%site_setting}}')
            ->queryScalar();

        $this->settings->putMany([
            'unknown_key' => 'hack',
            'facebook_url' => 'javascript:alert(1)',
        ], null);

        $countAfter = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%site_setting}}')
            ->queryScalar();

        assertSame($countBefore, $countAfter, 'Unknown keys and unsafe URLs must not be persisted.');
        assertSame('', $this->settings->get('facebook_url'));
    }

    public function testUnsafeUrlIsRejectedByValidator(): void
    {
        assertSame(false, $this->settings->isSafeUrl('javascript:alert(1)'));
        assertSame(false, $this->settings->isSafeUrl('data:text/html,x'));
        assertSame(false, $this->settings->isSafeUrl('not a url'));
        assertSame(true, $this->settings->isSafeUrl('https://facebook.com/page'));
        assertSame(true, $this->settings->isSafeUrl('http://example.com'));
    }
}
