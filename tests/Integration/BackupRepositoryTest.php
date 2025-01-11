<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Integration;

use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\tests\TestCaseBase\InitBackup;
use MuckiRestic\Entity\Result\ResultEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class BackupRepositoryTest extends TestCase
{
    public function testInitRepository(): void
    {
        $helper = new PluginHelper();
        $helper->deleteDirectory(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH
        );

        $this->CheckInitRepository();
    }

    public function CheckInitRepository()
    {
        $pluginSettingsMock = $this->createMock(PluginSettings::class);
        $pluginSettingsMock->method('getOwnResticBinaryPath')
            ->willReturn(TestCaseBaseDefaults::getResticPath());
        $backupRepository = new BackupRepository(
            $this->createMock(LoggerInterface::class),
            $pluginSettingsMock,
            $this->createMock(EntityRepository::class),
        );

        $backupRepositorySettings = InitBackup::getRepositoryInit();
        $initRepositoryResult = $backupRepository->initRepository($backupRepositorySettings);

        static::assertInstanceOf(
            ResultEntity::class, $initRepositoryResult, 'initRepository should return ResultEntity'
        );
        static::assertEquals(
            $backupRepositorySettings->getRepositoryPath(),
            $initRepositoryResult->getResticResponse()->repository,
            'initRepository result should return correct repository path'
        );

        static::assertEquals(
            'initialized',
            $initRepositoryResult->getResticResponse()->message_type,
            'initRepository result should return correct initialized message'
        );
    }
}
