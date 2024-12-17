<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiFacilityPlugin\Services\Settings as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

class SettingsTest extends TestCase
{
    public function testCheckSettingsIsEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $config = $this->createMock(SystemConfigService::class);
        $helper = $this->createMock(PluginHelper::class);

        $settingsClass = new PluginSettings($config, $kernel, $helper);
        $config->method('getBool')->willReturn(true);
        $isEnabled1 = $settingsClass->isEnabled();
        static::assertIsBool($isEnabled1, 'isEnabled method should return boolean');
        static::assertTrue($isEnabled1, 'isEnabled method should return true');
    }

    public function testCheckSettingsIsNotEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $config = $this->createMock(SystemConfigService::class);
        $helper = $this->createMock(PluginHelper::class);

        $settingsClass = new PluginSettings($config, $kernel, $helper);

        $config->method('getBool')->willReturn(false);
        $isEnabled2 = $settingsClass->isEnabled();
        static::assertIsBool($isEnabled2, 'isEnabled method should return boolean');
        static::assertFalse($isEnabled2, 'isEnabled method should return false');
    }
}
