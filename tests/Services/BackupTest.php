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
namespace MuckiFacilityPlugin\tests\Services;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\tests\TestCaseBase\CreateBackup;
use MuckiFacilityPlugin\Services\Settings as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\Backup as BackupService;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

class BackupTest extends TestCase
{
    public function testPrepareBackupPaths(): void
    {
        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRunnerFactory::class),
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class)
        );

        $prepareBackupPaths = $backupService->prepareBackupPaths(TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATHS);
        static ::assertIsArray($prepareBackupPaths, 'prepareBackupPaths method should return array');
        static ::assertCount(2, $prepareBackupPaths, 'prepareBackupPaths method should return array with 2 elements');

        /** @var BackupPathEntity $prepareBackupPath */
        foreach ($prepareBackupPaths as $prepareBackupPath) {
            static ::assertIsString(
                $prepareBackupPath->getId(),
                'prepareBackupPath should have a string value for key id'
            );
            static ::assertIsString(
                $prepareBackupPath->getBackupPath(),
                'prepareBackupPath should have a string value for key BackupPath'
            );
            static ::assertIsBool(
                $prepareBackupPath->isCompress(),
                'prepareBackupPath should have a bool value for key compress'
            );
            static ::assertIsInt(
                $prepareBackupPath->getPosition(), 'prepareBackupPath should have an integer value for key position'
            );
        }
    }

    public function testPrepareCreateBackup(): void
    {
        $backupRepositoryId = Uuid::randomHex();
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn(
            CreateBackup::getBackupRepositoryEntity(
                BackupTypes::FILES->value, $backupRepositoryId
            )
        );

        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRunnerFactory::class),
            $backupRepository,
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class)
        );

        $prepareCreateBackup = $backupService->prepareCreateBackup($backupRepositoryId);
        static ::assertInstanceOf(
            BackupRepositorySettings::class,
            $prepareCreateBackup,
            'prepareCreateBackup should return MuckiFacilityPlugin\Entity\BackupRepositorySettings'
        );

        static ::assertEquals(
            $backupRepositoryId,
            $prepareCreateBackup->getBackupRepositoryId(),
            'BackupRepositoryId should be equal'
        );

        static ::assertEquals(
            TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PASSWORD,
            $prepareCreateBackup->getRepositoryPassword(),
            'BackupRepositoryPassword should be equal'
        );

        static ::assertEquals(
            TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH,
            $prepareCreateBackup->getRepositoryPath(),
            'BackupRepositoryPath should be equal'
        );
    }
}
