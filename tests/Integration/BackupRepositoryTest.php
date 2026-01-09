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

use Shopware\Core\Framework\Uuid\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use MuckiRestic\Entity\Result\ResultEntity;

use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Backup as ServicesBackup;
use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\tests\TestCaseBase\InitBackup;
use MuckiFacilityPlugin\tests\TestCaseBase\CreateBackup;
use MuckiFacilityPlugin\tests\Services\HelperTest;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Backup\Files\FilesRunner;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Services\RestoreSnapshot;
use MuckiFacilityPlugin\tests\TestCaseBase\RestoreBackup;

class BackupRepositoryTest extends TestCase
{
    public function testInitRepository(): void
    {
        $backupRepositoryId = Uuid::randomHex();
        $helper = new PluginHelper();
        $helper->deleteDirectory(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH
        );

        $helper->deleteDirectory(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATH
        );
        $helper->deleteDirectory(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_RESTORE_PATH
        );

        $this->CheckInitRepository();
        $this->CreateBackup($backupRepositoryId);
        $snapshotId = $this->checkGetSnapshots($backupRepositoryId);
        $this->checkRestoreBySnapshotId($snapshotId);
    }

    public function CheckInitRepository(): void
    {
        $pluginSettingsMock = $this->createMock(PluginSettings::class);
        $pluginSettingsMock->method('hasOwnResticBinaryPath')->willReturn(true);
        $pluginSettingsMock->method('getOwnResticBinaryPath')->willReturn(
            TestCaseBaseDefaults::getResticPath()
        );
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

        static::assertTrue(
            file_exists($backupRepositorySettings->getRepositoryPath().'/config'),
            'initRepository should create a config file in the repository path'
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

    public function CreateBackup(string $backupRepositoryId): void
    {
        $backupRepositorySettings = CreateBackup::getCreateBackupEntity(
            BackupTypes::NONE_DATABASE->value,
            $backupRepositoryId
        );

        HelperTest::createTextFiles(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATH,
            TestCaseBaseDefaults::BACKUP_TEST_FILES
        );

        $pluginHelperMock = $this->createMock(PluginHelper::class);
        $pluginHelperMock->method('getCheckResults')->willReturn(
            array('check1', 'no errors were found')
        );

        $servicesCliOutputMock = $this->createMock(ServicesCliOutput::class);
        $loggerInterfaceMock = $this->createMock(LoggerInterface::class);
        $pluginSettingsMock = $this->createMock(PluginSettings::class);
        $pluginSettingsMock->method('hasOwnResticBinaryPath')->willReturn(true);
        $pluginSettingsMock->method('getOwnResticBinaryPath')->willReturn(
            TestCaseBaseDefaults::getResticPath()
        );

        $runner = new FilesRunner(
            $loggerInterfaceMock,
            $pluginSettingsMock,
            $backupRepositorySettings,
            $servicesCliOutputMock
        );

        $backupRunnerFactoryMock = $this->createMock(BackupRunnerFactory::class);
        $backupRunnerFactoryMock->method('createBackupRunner')
            ->willReturn($runner);

        $servicesBackup = new ServicesBackup(
            $loggerInterfaceMock,
            $backupRunnerFactoryMock,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $pluginSettingsMock,
            $pluginHelperMock,
            $this->createMock(ManageService::class),
            $servicesCliOutputMock
        );

        $servicesBackup->createBackup($backupRepositorySettings);

        $createBackupResults = $servicesBackup->getAllResults();
        static::assertCount(3, $createBackupResults, 'createBackup should return 3 results');

        $processed = end($createBackupResults)->getProcessed();
        static::assertEquals(
            'no errors were found',
            end($processed),
            'createBackup should return correct message: no errors were found'
        );
    }

    public function checkGetSnapshots(string $backupRepositoryId): string
    {
        $backupRepositoryEntity = new BackupRepositoryEntity();
        $backupRepositoryEntity->setRepositoryPath(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH
        );
        $backupRepositoryEntity->setRepositoryPassword(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PASSWORD);

        $backupRepositoryMock = $this->createMock(BackupRepository::class);
        $backupRepositoryMock->method('getBackupRepositoryById')->willReturn($backupRepositoryEntity);

        $pluginSettingsMock = $this->createMock(PluginSettings::class);
        $pluginSettingsMock->method('hasOwnResticBinaryPath')->willReturn(true);
        $pluginSettingsMock->method('getOwnResticBinaryPath')->willReturn(TestCaseBaseDefaults::getResticPath());

        $manageService = new ManageService(
            $this->createMock(LoggerInterface::class),
            $backupRepositoryMock,
            $this->createMock(BackupFileSnapshotsRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $pluginSettingsMock,
            $this->createMock(PluginHelper::class)
        );

        $snapshots = json_decode($manageService->getSnapshots($backupRepositoryId), true);

        static::assertCount(1, $snapshots, 'getSnapshots should return 1 snapshot');
        static::assertIsString($snapshots[0]['id'], 'getSnapshots should return snapshot with id');
        static::assertCount(1, $snapshots[0]['paths'], 'getSnapshots should return snapshot with paths');
        static::assertEquals(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATH,
            $snapshots[0]['paths'][0],
            'getSnapshots path should return the test backup path'
        );

        return $snapshots[0]['id'];
    }

    public function checkRestoreBySnapshotId(string $snapshotId): void
    {
        $pluginSettingsMock = $this->createMock(PluginSettings::class);
        $pluginSettingsMock->method('hasOwnResticBinaryPath')->willReturn(true);
        $pluginSettingsMock->method('getOwnResticBinaryPath')->willReturn(TestCaseBaseDefaults::getResticPath());

        $restoreSnapshot = new RestoreSnapshot(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRepository::class),
            $pluginSettingsMock,
            $this->createMock(PluginHelper::class),
            $this->createMock(ServicesCliOutput::class)
        );

        $restoreSnapshot->restoreSnapshot(RestoreBackup::getRestoreBackupEntity($snapshotId));
        $allResults = $restoreSnapshot->getAllResults();

        static::assertCount(1, $allResults, 'restoreSnapshot should return 1 result');
        $restoreFolder = TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_RESTORE_PATH.TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATH;
        $restoreFiles = $this->getFilesFromDirectory($restoreFolder);
        static::assertCount(
            count(TestCaseBaseDefaults::BACKUP_TEST_FILES),
            $restoreFiles,
            'restoreSnapshot should restore 3 files'
        );
    }

    private function getFilesFromDirectory(string $directory): array
    {
        $files = [];

        if (is_dir($directory)) {
            $dirHandle = opendir($directory);
            if ($dirHandle) {
                while (($file = readdir($dirHandle)) !== false) {
                    if ($file !== '.' && $file !== '..' && is_file($directory . '/' . $file)) {
                        $files[] = $file;
                    }
                }
                closedir($dirHandle);
            }
        }

        return $files;
    }
}
