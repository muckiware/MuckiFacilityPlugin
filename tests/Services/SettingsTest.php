<?php

declare(strict_types=1);

namespace Services;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiLogPlugin\Services\Helper;
use MuckiLogPlugin\Core\ConfigPath;
use MuckiLogPlugin\Services\Settings as PluginSettings;
use MuckiLogPlugin\Core\LogLevel;
use MuckiLogPlugin\Core\Defaults as PluginDefaults;

class SettingsTest extends TestCase
{
    public function testCheckSettingsIsEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $config = $this->createMock(SystemConfigService::class);

        $settingsClass = new PluginSettings($config, $kernel);
        $config->method('getBool')->willReturn(true);
        $isEnabled1 = $settingsClass->isEnabled();
        static::assertIsBool($isEnabled1, 'isEnabled method should return boolean');
        static::assertTrue($isEnabled1, 'isEnabled method should return true');
    }

    public function testCheckSettingsIsNotEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $config = $this->createMock(SystemConfigService::class);

        $settingsClass = new PluginSettings($config, $kernel);

        $config->method('getBool')->willReturn(false);
        $isEnabled2 = $settingsClass->isEnabled();
        static::assertIsBool($isEnabled2, 'isEnabled method should return boolean');
        static::assertFalse($isEnabled2, 'isEnabled method should return false');
    }
}
