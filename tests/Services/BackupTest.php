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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\tests\TestCaseBase\CreateBackup;
use MuckiFacilityPlugin\Services\Settings as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\Backup as BackupService;
use MuckiFacilityPlugin\Services\BackupCommandExecutor;
use MuckiFacilityPlugin\Subscriber\BackupCommandSubscriber;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\RepositoryStats;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;

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
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $this->createMock(EventDispatcherInterface::class)
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
        $backupRepositoryEntity = CreateBackup::getBackupRepositoryEntity(
            BackupTypes::FILES->value,
            $backupRepositoryId
        );
        $backupRepositoryEntity->setName('Daily backup');
        $backupRepositoryEntity->setPreBackupCommandType('batch');
        $backupRepositoryEntity->setPreBackupCommand('/hooks/pre.sh');
        $backupRepositoryEntity->setPreBackupFailOnError(false);
        $backupRepositoryEntity->setPostBackupCommandType('php');
        $backupRepositoryEntity->setPostBackupCommand('cache:clear');
        $backupRepositoryEntity->setPostBackupFailOnError(true);
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn($backupRepositoryEntity);

        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRunnerFactory::class),
            $backupRepository,
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $this->createMock(EventDispatcherInterface::class)
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

        static ::assertSame('Daily backup', $prepareCreateBackup->getName());

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
        static::assertSame('batch', $prepareCreateBackup->getPreBackupCommandType());
        static::assertSame('/hooks/pre.sh', $prepareCreateBackup->getPreBackupCommand());
        static::assertFalse($prepareCreateBackup->isPreBackupFailOnError());
        static::assertSame('php', $prepareCreateBackup->getPostBackupCommandType());
        static::assertSame('cache:clear', $prepareCreateBackup->getPostBackupCommand());
        static::assertTrue($prepareCreateBackup->isPostBackupFailOnError());
    }

    public function testPrepareCreateBackupCarriesDatabaseConnectionId(): void
    {
        $connectionId = Uuid::randomHex();
        $backupRepositoryId = Uuid::randomHex();

        foreach ([$connectionId, null] as $expected) {
            $entity = CreateBackup::getBackupRepositoryEntity(BackupTypes::FILES->value, $backupRepositoryId);
            $entity->setName('Daily backup');
            $entity->setDatabaseConnectionId($expected);
            $backupRepository = $this->createMock(BackupRepository::class);
            $backupRepository->method('getBackupRepositoryById')->willReturn($entity);

            $backupService = new BackupService(
                $this->createMock(LoggerInterface::class),
                $this->createMock(BackupRunnerFactory::class),
                $backupRepository,
                $this->createMock(BackupRepositoryChecks::class),
                $this->createMock(PluginSettings::class),
                $this->createMock(PluginHelper::class),
                $this->createMock(ManageService::class),
                $this->createMock(ServicesCliOutput::class),
                $this->createMock(RepositoryStats::class),
                $this->createMock(EventDispatcherInterface::class)
            );

            static::assertSame($expected, $backupService->prepareCreateBackup($backupRepositoryId)->getDatabaseConnectionId());
        }
    }

    public function testNewSettingsDefaultToStandardConnection(): void
    {
        static::assertNull((new BackupRepositorySettings())->getDatabaseConnectionId());
    }

    public function testCreateBackupWithoutFilePathsRunsNoFilesBackup(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupRepositoryId(Uuid::randomHex());
        $createBackup->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $createBackup->setBackupPaths([]);
        $createBackup->setDbDumpPath('/tmp/muwa-db-dump');

        $backupRunner = $this->createMock(BackupInterface::class);
        $backupRunner->method('getBackupResults')->willReturn([]);

        $runnerCalls = [];
        $backupRunnerFactory = $this->createMock(BackupRunnerFactory::class);
        $backupRunnerFactory->method('createBackupRunner')->willReturnCallback(
            function (BackupRepositorySettings $settings) use (&$runnerCalls, $backupRunner): BackupInterface {
                $runnerCalls[] = [$settings->getBackupType(), count($settings->getBackupPaths())];

                return $backupRunner;
            }
        );

        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $backupRunnerFactory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $this->createMock(EventDispatcherInterface::class)
        );

        $backupService->createBackup($createBackup);

        static::assertNotContains(
            [BackupTypes::FILES->value, 0],
            $runnerCalls,
            'createBackup should not start a files backup when the repository has no backup paths configured'
        );
    }

    public function testCreateBackupCollectsRepositoryStats(): void
    {
        $backupRepositoryId = Uuid::randomHex();

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->expects(static::once())
            ->method('collectAndSave')
            ->with($backupRepositoryId);

        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRunnerFactory::class),
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $repositoryStats,
            $this->createMock(EventDispatcherInterface::class)
        );

        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupRepositoryId($backupRepositoryId);
        $createBackup->setBackupType(BackupTypes::NONE_DATABASE->value);
        $createBackup->setBackupPaths([]);

        $backupService->createBackup($createBackup, false);
    }

    public function testCreateBackupRunsSubscriberCommandsAtOuterBoundaries(): void
    {
        $repositoryId = Uuid::randomHex();
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId($repositoryId);
        $settings->setBackupType(BackupTypes::NONE_DATABASE->value);
        $settings->setBackupPaths([]);
        $settings->setPreBackupCommandType('batch');
        $settings->setPreBackupCommand('/hooks/pre.sh');
        $settings->setPreBackupFailOnError(true);
        $settings->setPostBackupCommandType('php');
        $settings->setPostBackupCommand('cache:clear');
        $settings->setPostBackupFailOnError(false);

        $runner = $this->createMock(BackupInterface::class);
        $runner->method('getBackupResults')->willReturn([]);
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturn($runner);

        $sequence = [];
        $executor = $this->createMock(BackupCommandExecutor::class);
        $executor->expects(static::exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $type, string $command, bool $failOnError) use (&$sequence): void {
                $sequence[] = [$type, $command, $failOnError];
            });
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new BackupCommandSubscriber($executor));

        $manageService = $this->createMock(ManageService::class);
        $manageService->method('saveSnapshots')->willReturnCallback(static function () use (&$sequence): void {
            $sequence[] = 'snapshots';
        });
        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('collectAndSave')->willReturnCallback(static function () use (&$sequence): void {
            $sequence[] = 'stats';
        });

        $service = new BackupService(
            $this->createMock(LoggerInterface::class),
            $factory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $manageService,
            $this->createMock(ServicesCliOutput::class),
            $repositoryStats,
            $dispatcher
        );

        $service->createBackup($settings, false);

        static::assertSame([
            ['batch', '/hooks/pre.sh', true],
            'snapshots',
            'stats',
            ['php', 'cache:clear', false],
        ], $sequence);
    }

    public function testOwnConnectionResolveFailureAbortsBackupRun(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId(Uuid::randomHex());
        $settings->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $settings->setBackupPaths([]);
        $settings->setDbDumpPath('/tmp/muwa-db-dump');

        $runnerTypes = [];
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturnCallback(
            static function (BackupRepositorySettings $s) use (&$runnerTypes): BackupInterface {
                $runnerTypes[] = $s->getBackupType();
                throw new DatabaseBackupFailedException('Database connection "x" not found');
            }
        );

        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AfterBackupCreatedEvent::NAME, static function () use (&$events): void {
            $events[] = AfterBackupCreatedEvent::NAME;
        });

        $service = new BackupService(
            $this->createMock(LoggerInterface::class),
            $factory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $dispatcher
        );

        try {
            $service->createBackup($settings, false);
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException) {
        }

        static::assertSame([BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value], $runnerTypes, 'No files runner for the dump folder');
        static::assertSame([], $events, 'No AfterBackupCreatedEvent');
    }
}
