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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
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
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BackupFinishedEvent;
use MuckiFacilityPlugin\MessageQueue\Message\MirrorRepositoryMessage;
use MuckiFacilityPlugin\Backup\Database\DumpFailureAwareInterface;
use MuckiFacilityPlugin\Events\AfterDatabaseDumpCreatedEvent;
use MuckiFacilityPlugin\Events\DatabaseDumpFailedEvent;
use MuckiFacilityPlugin\Core\CheckStatus;
use MuckiRestic\Entity\Result\ResultEntity;

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
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(MessageBusInterface::class)
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
        $backupRepositoryEntity->setActive(true);
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
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $prepareCreateBackup = $backupService->prepareCreateBackup($backupRepositoryId);
        static::assertTrue($prepareCreateBackup->isActive(), 'prepareCreateBackup must carry the active flag');
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
                $this->createMock(EventDispatcherInterface::class),
                $this->createMock(MessageBusInterface::class)
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
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(MessageBusInterface::class)
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
            ->method('collectAndSaveForBackup')
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
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupRepositoryId($backupRepositoryId);
        $createBackup->setBackupType(BackupTypes::NONE_DATABASE->value);
        $createBackup->setBackupPaths([]);

        $backupService->createBackup($createBackup, false);
    }

    public function testCreateBackupResetsRunStateBeforeDispatchingBeforeEvent(): void
    {
        $firstSettings = new BackupRepositorySettings();
        $firstSettings->setBackupRepositoryId('first-run');
        $firstSettings->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $firstSettings->setBackupPaths([]);
        $firstSettings->setDbDumpPath('/tmp/muwa-state-reset-test');

        $result = new ResultEntity();
        $result->setOutput('first-run-result');

        $databaseRunner = $this->createMockForIntersectionOfInterfaces([
            BackupInterface::class,
            DumpFailureAwareInterface::class,
        ]);
        $databaseRunner->method('getBackupResults')->willReturn([$result]);
        $databaseRunner->method('getDumpFailures')->willReturn(['first-run-dump-failure']);

        $filesRunner = $this->createMock(BackupInterface::class);
        $filesRunner->method('getBackupResults')->willReturn([]);

        $checkRunner = $this->createMock(BackupInterface::class);
        $checkRunner->method('checkBackupData')->willThrowException(new \RuntimeException('first-run-check-failure'));

        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturnOnConsecutiveCalls(
            $databaseRunner,
            $filesRunner,
            $checkRunner
        );

        $pluginSettings = $this->createMock(PluginSettings::class);
        $pluginSettings->method('getBackupPath')->willReturn('/tmp/muwa-state-reset-test');

        $helper = $this->createMock(PluginHelper::class);
        $helper->method('deleteDirectory')->willReturn(true);

        $checks = $this->createMock(BackupRepositoryChecks::class);
        $checks->method('saveNewCheck')->willReturn('check-id');

        $beforeCalls = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            BeforeBackupCreatedEvent::NAME,
            static function () use (&$beforeCalls): void {
                ++$beforeCalls;
                if ($beforeCalls === 2) {
                    throw new \RuntimeException('second run rejected by before listener');
                }
            }
        );

        $service = new BackupService(
            $this->createMock(LoggerInterface::class),
            $factory,
            $this->createMock(BackupRepository::class),
            $checks,
            $pluginSettings,
            $helper,
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $dispatcher,
            $this->createMock(MessageBusInterface::class)
        );

        $service->createBackup($firstSettings, false);
        static::assertSame([$result], $service->getAllResults());
        static::assertNotNull($service->getCheckFailure());
        static::assertNotNull($service->getDumpFailure());

        $secondSettings = new BackupRepositorySettings();
        $secondSettings->setBackupRepositoryId('second-run');
        $secondSettings->setBackupType(BackupTypes::NONE_DATABASE->value);
        $secondSettings->setBackupPaths([]);

        try {
            $service->createBackup($secondSettings, false);
            static::fail('Expected the second run to fail in the before listener');
        } catch (\RuntimeException $exception) {
            static::assertSame('second run rejected by before listener', $exception->getMessage());
        }

        static::assertSame([], $service->getAllResults());
        static::assertNull($service->getCheckFailure());
        static::assertNull($service->getDumpFailure());
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
        $repositoryStats->method('collectAndSaveForBackup')->willReturnCallback(static function () use (&$sequence): ?string {
            $sequence[] = 'stats';
            return null;
        });
        $repositoryStats->method('saveBackupDuration')->willReturnCallback(static function () use (&$sequence): void {
            $sequence[] = 'duration';
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
            $dispatcher,
            $this->createMock(MessageBusInterface::class)
        );

        $service->createBackup($settings, false);

        static::assertSame([
            ['batch', '/hooks/pre.sh', true],
            'snapshots',
            'stats',
            ['php', 'cache:clear', false],
            'duration',
        ], $sequence);
    }

    public function testCreateBackupSavesDurationOnTheStatsEntryOfTheRun(): void
    {
        $backupRepositoryId = Uuid::randomHex();

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('collectAndSaveForBackup')->willReturn('stats-id');
        $repositoryStats->expects(static::once())
            ->method('saveBackupDuration')
            ->with($backupRepositoryId, 'stats-id', static::greaterThanOrEqual(0));

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
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupRepositoryId($backupRepositoryId);
        $createBackup->setBackupType(BackupTypes::NONE_DATABASE->value);
        $createBackup->setBackupPaths([]);

        $backupService->createBackup($createBackup, false);
    }

    public function testFailedBackupRunStillSavesItsDuration(): void
    {
        $backupRepositoryId = Uuid::randomHex();
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId($backupRepositoryId);
        $settings->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $settings->setBackupPaths([]);
        $settings->setDbDumpPath('/tmp/muwa-db-dump');

        $primaryError = new DatabaseBackupFailedException('Database connection "x" not found');
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willThrowException($primaryError);

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->expects(static::never())->method('collectAndSaveForBackup');
        $repositoryStats->expects(static::once())
            ->method('saveBackupDuration')
            ->with($backupRepositoryId, null, static::greaterThanOrEqual(0));

        $finishedEvents = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BackupFinishedEvent::NAME, static function (BackupFinishedEvent $event) use (&$finishedEvents): void {
            $finishedEvents[] = $event;
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
            $repositoryStats,
            $dispatcher,
            $this->createMock(MessageBusInterface::class)
        );

        try {
            $service->createBackup($settings, false);
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException $exception) {
            static::assertSame($primaryError, $exception);
        }

        static::assertCount(1, $finishedEvents);
        static::assertSame($primaryError, $finishedEvents[0]->getPrimaryError());
        static::assertFalse($finishedEvents[0]->isCoreSucceeded());
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
            $dispatcher,
            $this->createMock(MessageBusInterface::class)
        );

        try {
            $service->createBackup($settings, false);
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException) {
        }

        static::assertSame([BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value], $runnerTypes, 'No files runner for the dump folder');
        static::assertSame([], $events, 'No AfterBackupCreatedEvent');
    }

    /**
     * @param array<int, string> $sequence
     */
    private function createMirrorBackupService(
        ?string $mirrorConnectionId,
        MessageBusInterface $bus,
        array &$sequence,
        ?\Throwable $snapshotsError = null
    ): BackupService
    {
        $repositoryId = 'repo-id';
        $entity = new BackupRepositoryEntity();
        $entity->setId($repositoryId);
        $entity->setMirrorS3ConnectionId($mirrorConnectionId);
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn($entity);

        $runner = $this->createMock(BackupInterface::class);
        $runner->method('getBackupResults')->willReturn([]);
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturn($runner);

        $manageService = $this->createMock(ManageService::class);
        $manageService->method('saveSnapshots')->willReturnCallback(static function () use (&$sequence, $snapshotsError): void {
            $sequence[] = 'snapshots';
            if ($snapshotsError !== null) {
                throw $snapshotsError;
            }
        });
        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('collectAndSaveForBackup')->willReturnCallback(static function () use (&$sequence): ?string {
            $sequence[] = 'stats';
            return null;
        });

        return new BackupService(
            $this->createMock(LoggerInterface::class),
            $factory,
            $backupRepository,
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $manageService,
            $this->createMock(ServicesCliOutput::class),
            $repositoryStats,
            $this->createMock(EventDispatcherInterface::class),
            $bus
        );
    }

    private function createMirrorSettings(): BackupRepositorySettings
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId('repo-id');
        $settings->setBackupType(BackupTypes::NONE_DATABASE->value);
        $settings->setBackupPaths([]);

        return $settings;
    }

    public function testDispatchesMirrorMessageAfterSnapshotsAndStatsWhenConfigured(): void
    {
        $sequence = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(static::once())->method('dispatch')->with(static::callback(
            static fn (object $m): bool => $m instanceof MirrorRepositoryMessage
                && $m->getBackupRepositoryId() === 'repo-id'
        ))->willReturnCallback(static function (object $m) use (&$sequence): Envelope {
            $sequence[] = 'mirror';
            return new Envelope($m);
        });

        $this->createMirrorBackupService('conn-id', $bus, $sequence)->createBackup($this->createMirrorSettings(), false);

        static::assertSame(['snapshots', 'stats', 'mirror'], $sequence);
    }

    public function testNoMirrorMessageWithoutConnection(): void
    {
        $sequence = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(static::never())->method('dispatch');

        $this->createMirrorBackupService(null, $bus, $sequence)->createBackup($this->createMirrorSettings(), false);
    }

    public function testMirrorDispatchFailureDoesNotBreakBackup(): void
    {
        $sequence = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $this->createMirrorBackupService('conn-id', $bus, $sequence)->createBackup($this->createMirrorSettings(), false);

        static::assertSame(['snapshots', 'stats'], $sequence);
    }

    public function testMirrorIsStillQueuedWhenSavingSnapshotsFails(): void
    {
        $sequence = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(static::once())->method('dispatch')
            ->willReturnCallback(static function (object $m) use (&$sequence): Envelope {
                $sequence[] = 'mirror';
                return new Envelope($m);
            });
        $service = $this->createMirrorBackupService('conn-id', $bus, $sequence, new \RuntimeException('snapshots failed'));

        try {
            $service->createBackup($this->createMirrorSettings(), false);
            static::fail('Expected the snapshot error to be rethrown.');
        } catch (\RuntimeException $exception) {
            static::assertSame('snapshots failed', $exception->getMessage());
        }

        static::assertSame(['snapshots', 'mirror'], $sequence);
    }

    public function testFailingCheckSavesRedCheckAndMirrorMessageCarriesItsId(): void
    {
        $entity = new BackupRepositoryEntity();
        $entity->setId('repo-id');
        $entity->setMirrorS3ConnectionId('conn-id');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn($entity);

        $runner = $this->createMock(BackupInterface::class);
        $runner->method('checkBackupData')->willThrowException(
            new \RuntimeException('The command "restic check" failed. Exit Code: 1, password pw-secret-1')
        );
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturn($runner);

        $savedStatus = null;
        $checks = $this->createMock(BackupRepositoryChecks::class);
        $checks->expects(static::once())->method('saveNewCheck')->willReturnCallback(
            static function (string $repositoryId, string $status) use (&$savedStatus): string {
                $savedStatus = $status;
                return 'red-check-id';
            }
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(static::once())->method('dispatch')->with(static::callback(
            static fn (object $m): bool => $m instanceof MirrorRepositoryMessage && $m->getCheckId() === 'red-check-id'
        ))->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $service = new BackupService(
            $this->createMock(LoggerInterface::class),
            $factory,
            $backupRepository,
            $checks,
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $this->createMock(EventDispatcherInterface::class),
            $bus
        );
        $settings = $this->createMirrorSettings();
        $settings->setRepositoryPassword('pw-secret-1');

        $service->createBackup($settings, false);

        static::assertIsString($savedStatus);
        static::assertStringStartsWith('check failed: ', $savedStatus);
        static::assertStringNotContainsString('pw-secret-1', $savedStatus);
        static::assertFalse(\MuckiFacilityPlugin\Core\CheckStatus::isHealthy($savedStatus));
    }

    public function testCheckFailureStatusNeverLooksHealthy(): void
    {
        $runner = $this->createMock(BackupInterface::class);
        $runner->method('checkBackupData')->willThrowException(new \RuntimeException('No errors were found'));
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturn($runner);
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
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $service->checkBackup($this->createMirrorSettings());

        static::assertSame('check failed', $service->getCheckFailure());
    }

    public function testDefaultConnectionDumpFailureSavesRedCheckAfterGreenOneAndMirrorKeepsGreenId(): void
    {
        $saved = [];
        $events = [];
        $mirrorCheckId = null;
        $service = $this->createDumpFailureService(['Dump failed:boom'], false, $saved, $events, $mirrorCheckId);

        $service->createBackup($this->createDumpFailureSettings(), false);

        static::assertCount(2, $saved);
        static::assertSame('no errors were found', $saved[0]['status']);
        static::assertNull($saved[0]['createdAt']);
        static::assertSame('database dump failed: Dump failed:boom', $saved[1]['status']);
        static::assertInstanceOf(\DateTimeInterface::class, $saved[1]['createdAt']);
        static::assertFalse(CheckStatus::isHealthy($saved[1]['status']));
        static::assertSame('check-1', $mirrorCheckId, 'the repository is fine, the mirror keeps the restic check');
        static::assertSame('database dump failed: Dump failed:boom', $service->getDumpFailure());

        static::assertSame([DatabaseDumpFailedEvent::EVENT_NAME, AfterBackupCreatedEvent::NAME], array_column($events, 'name'));
        $failedEvent = $events[0]['event'];
        static::assertInstanceOf(DatabaseDumpFailedEvent::class, $failedEvent);
        static::assertFalse($failedEvent->isBackupAborted());
        static::assertSame(['Dump failed:boom'], $failedEvent->getFailures());
    }

    public function testFailOnDumpErrorAbortsTheBackupRun(): void
    {
        $saved = [];
        $events = [];
        $mirrorCheckId = null;
        $service = $this->createDumpFailureService(['Dump failed:boom'], true, $saved, $events, $mirrorCheckId);

        try {
            $service->createBackup($this->createDumpFailureSettings(), false);
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringContainsString('failOnDumpError', $e->getMessage());
            static::assertStringContainsString('Dump failed:boom', $e->getMessage());
        }

        static::assertSame([DatabaseDumpFailedEvent::EVENT_NAME], array_column($events, 'name'));
        static::assertTrue($events[0]['event']->isBackupAborted());
        static::assertSame([], $saved);
        static::assertNull($mirrorCheckId);
    }

    public function testSuccessfulDumpKeepsAfterDumpEventAndSavesNoExtraCheck(): void
    {
        $saved = [];
        $events = [];
        $mirrorCheckId = null;
        $service = $this->createDumpFailureService([], false, $saved, $events, $mirrorCheckId);

        $service->createBackup($this->createDumpFailureSettings(), false);

        static::assertSame(['no errors were found'], array_column($saved, 'status'));
        static::assertSame([AfterDatabaseDumpCreatedEvent::NAME, AfterBackupCreatedEvent::NAME], array_column($events, 'name'));
        static::assertNull($service->getDumpFailure());
    }

    public function testDumpFailureStatusCountsFailuresAndNeverLooksHealthy(): void
    {
        $saved = [];
        $events = [];
        $mirrorCheckId = null;
        $service = $this->createDumpFailureService(['No errors were found', 'other'], false, $saved, $events, $mirrorCheckId);

        $service->createBackup($this->createDumpFailureSettings(), false);

        static::assertSame('database dump failed', $saved[1]['status']);

        $saved = [];
        $service = $this->createDumpFailureService(['Dump failed:a', 'Dump failed:b'], false, $saved, $events, $mirrorCheckId);
        $service->createBackup($this->createDumpFailureSettings(), false);

        static::assertSame('database dump failed: (2 dumps) Dump failed:a', $saved[1]['status']);
    }

    public function testFailingDumpFailedListenerDoesNotChangeTheRun(): void
    {
        $saved = [];
        $events = [];
        $mirrorCheckId = null;
        $service = $this->createDumpFailureService(['Dump failed:boom'], false, $saved, $events, $mirrorCheckId, true);

        $service->createBackup($this->createDumpFailureSettings(), false);

        static::assertCount(2, $saved);
        static::assertContains(AfterBackupCreatedEvent::NAME, array_column($events, 'name'));
    }

    public function testDumpFolderIsClearedEvenWhenTheRunAborts(): void
    {
        $saved = [];
        $events = [];
        $mirrorCheckId = null;
        $deleted = [];
        $service = $this->createDumpFailureService(['Dump failed:boom'], true, $saved, $events, $mirrorCheckId, false, $deleted);

        try {
            $service->createBackup($this->createDumpFailureSettings(), false);
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException) {
        }

        static::assertSame(['/tmp/muwa-dump-failure-test', '/tmp/muwa-dump-failure-test'], $deleted, 'before and after the dump');
    }

    private function createDumpFailureSettings(): BackupRepositorySettings
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId('repo-id');
        $settings->setName('Shop');
        $settings->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $settings->setBackupPaths([]);

        return $settings;
    }

    /**
     * @param array<int, string> $dumpFailures
     * @param array<int, array{status: string, createdAt: ?\DateTimeInterface}> $saved
     * @param array<int, array{name: string, event: object}> $events
     * @param array<int, string> $deleted
     */
    private function createDumpFailureService(
        array $dumpFailures,
        bool $failOnDumpError,
        array &$saved,
        array &$events,
        ?string &$mirrorCheckId,
        bool $throwingListener = false,
        array &$deleted = []
    ): BackupService {
        $result = new ResultEntity();
        $result->setOutput('restic check output');

        $runner = $this->createMockForIntersectionOfInterfaces([BackupInterface::class, DumpFailureAwareInterface::class]);
        $runner->method('getDumpFailures')->willReturn($dumpFailures);
        // dump runner, files runner (dump folder), check runner: only `restic check` has an output
        $runner->method('getBackupResults')->willReturnOnConsecutiveCalls([], [], [$result]);
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturn($runner);

        $checks = $this->createMock(BackupRepositoryChecks::class);
        $checks->method('saveNewCheck')->willReturnCallback(
            static function (string $repositoryId, string $status, ?\DateTimeInterface $createdAt = null) use (&$saved): string {
                $saved[] = ['status' => $status, 'createdAt' => $createdAt];
                return 'check-' . count($saved);
            }
        );

        $entity = new BackupRepositoryEntity();
        $entity->setId('repo-id');
        $entity->setMirrorS3ConnectionId('conn-id');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn($entity);

        $pluginSettings = $this->createMock(PluginSettings::class);
        $pluginSettings->method('getBackupPath')->willReturn('/tmp/muwa-dump-failure-test');
        $pluginSettings->method('isFailOnDumpErrorEnabled')->willReturn($failOnDumpError);

        $helper = $this->createMock(PluginHelper::class);
        $helper->method('deleteDirectory')->willReturnCallback(static function (string $path) use (&$deleted): bool {
            $deleted[] = $path;
            return true;
        });
        $helper->method('getCheckResults')->willReturn(['no errors were found']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$mirrorCheckId): Envelope {
            if ($message instanceof MirrorRepositoryMessage) {
                $mirrorCheckId = $message->getCheckId();
            }
            return new Envelope($message);
        });

        $dispatcher = new EventDispatcher();
        foreach ([DatabaseDumpFailedEvent::EVENT_NAME, AfterDatabaseDumpCreatedEvent::NAME, AfterBackupCreatedEvent::NAME] as $name) {
            $dispatcher->addListener($name, static function (object $event) use (&$events, $name): void {
                $events[] = ['name' => $name, 'event' => $event];
            });
        }
        if ($throwingListener) {
            $dispatcher->addListener(DatabaseDumpFailedEvent::EVENT_NAME, static function (): void {
                throw new \RuntimeException('flow action failed');
            });
        }

        return new BackupService(
            $this->createMock(LoggerInterface::class),
            $factory,
            $backupRepository,
            $checks,
            $pluginSettings,
            $helper,
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $this->createMock(RepositoryStats::class),
            $dispatcher,
            $bus
        );
    }

}
