<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Services;

use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Events\AfterBackupCheckedEvent;
use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiFacilityPlugin\Events\AfterDatabaseDumpCreatedEvent;
use MuckiFacilityPlugin\Events\AfterRepositoryCleanedUpEvent;
use MuckiFacilityPlugin\Events\AfterSnapshotDeletedEvent;
use MuckiFacilityPlugin\Events\AfterSnapshotsDeletedEvent;
use MuckiFacilityPlugin\Events\AfterSnapshotsForgottenEvent;
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BeforeDatabaseDumpCreatedEvent;
use MuckiFacilityPlugin\Services\Backup;
use MuckiFacilityPlugin\Services\BackupCommandExecutor;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\ManageRepository;
use MuckiFacilityPlugin\Services\RepositoryStats;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Library\Manage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcher;

class LifecycleEventDispatchTest extends TestCase
{
    public function testBackupLifecycleEventsAreDispatchedInOrder(): void
    {
        $repositoryId = Uuid::randomHex();
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId($repositoryId);
        $settings->setBackupType(BackupTypes::FILES->value);
        $path = new BackupPathEntity();
        $path->setBackupPath('/tmp/files');
        $path->setPosition(0);
        $path->setCompress(false);
        $settings->setBackupPaths([$path]);

        $runner = $this->createMock(BackupInterface::class);
        $runner->method('getBackupResults')->willReturn([]);
        $runnerFactory = $this->createMock(BackupRunnerFactory::class);
        $runnerFactory->method('createBackupRunner')->willReturn($runner);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(BeforeBackupCreatedEvent::NAME, static function (BeforeBackupCreatedEvent $event) use (&$events): void {
            $events[] = ['before', $event->getBackupSettings()->getBackupRepositoryId()];
        });
        $dispatcher->addListener(AfterBackupCreatedEvent::NAME, static function (AfterBackupCreatedEvent $event) use (&$events): void {
            $events[] = ['after', $event->getBackupSettings()->getBackupRepositoryId()];
        });

        $service = new Backup(
            $this->createMock(LoggerInterface::class),
            $runnerFactory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(Helper::class),
            $this->createMock(ManageRepository::class),
            $this->createMock(CliOutput::class),
            $this->createMock(RepositoryStats::class),
            $dispatcher,
            $this->createMock(BackupCommandExecutor::class)
        );

        $service->createBackup($settings, false);

        static::assertSame([
            ['before', $repositoryId],
            ['after', $repositoryId],
        ], $events);
    }

    public function testDatabaseDumpEventsAreDispatchedAroundSuccessfulDump(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);

        $result = new ResultEntity();
        $result->setOutput('dump created');
        $sequence = [];
        $runner = $this->createMock(BackupInterface::class);
        $runner->expects(static::once())
            ->method('createBackupData')
            ->with(false)
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'dump';
            });
        $runner->method('getBackupResults')->willReturn([$result]);
        $runnerFactory = $this->createMock(BackupRunnerFactory::class);
        $runnerFactory->method('createBackupRunner')->willReturn($runner);

        $dispatcher = new EventDispatcher();
        $payloads = [];
        $dispatcher->addListener(BeforeDatabaseDumpCreatedEvent::NAME, static function (BeforeDatabaseDumpCreatedEvent $event) use (&$sequence, &$payloads): void {
            $sequence[] = 'before';
            $payloads[] = $event->getBackupSettings();
        });
        $dispatcher->addListener(AfterDatabaseDumpCreatedEvent::NAME, static function (AfterDatabaseDumpCreatedEvent $event) use (&$sequence, &$payloads): void {
            $sequence[] = 'after';
            $payloads[] = [$event->getBackupSettings(), $event->getResults()];
        });

        $service = new Backup(
            $this->createMock(LoggerInterface::class),
            $runnerFactory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(Helper::class),
            $this->createMock(ManageRepository::class),
            $this->createMock(CliOutput::class),
            $this->createMock(RepositoryStats::class),
            $dispatcher,
            $this->createMock(BackupCommandExecutor::class)
        );

        $service->createDump($settings);

        static::assertSame(['before', 'dump', 'after'], $sequence);
        static::assertSame([$settings, [$settings, [$result]]], $payloads);
    }

    public function testBackupCheckedEventContainsRepositoryAndCheckResult(): void
    {
        $repositoryId = Uuid::randomHex();
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId($repositoryId);

        $repository = new BackupRepositoryEntity();
        $repository->setId($repositoryId);
        $repository->setName('Daily backup');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with($repositoryId)->willReturn($repository);

        $result = new ResultEntity();
        $result->setOutput('restic check output');
        $sequence = [];
        $runner = $this->createMock(BackupInterface::class);
        $runner->expects(static::once())
            ->method('checkBackupData')
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'checked';
            });
        $runner->method('getBackupResults')->willReturn([$result]);
        $runnerFactory = $this->createMock(BackupRunnerFactory::class);
        $runnerFactory->method('createBackupRunner')->willReturn($runner);

        $helper = $this->createMock(Helper::class);
        $helper->method('getCheckResults')->with('restic check output')->willReturn(['no errors were found']);
        $checks = $this->createMock(BackupRepositoryChecks::class);
        $checks->expects(static::once())
            ->method('saveNewCheck')
            ->with($repositoryId, 'no errors were found')
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'saved';
            });

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(AfterBackupCheckedEvent::NAME, static function (AfterBackupCheckedEvent $event) use (&$events, &$sequence): void {
            $sequence[] = 'event';
            $events[] = [$event->getBackupRepository(), $event->getCheckResult()];
        });

        $service = new Backup(
            $this->createMock(LoggerInterface::class),
            $runnerFactory,
            $backupRepository,
            $checks,
            $this->createMock(SettingsInterface::class),
            $helper,
            $this->createMock(ManageRepository::class),
            $this->createMock(CliOutput::class),
            $this->createMock(RepositoryStats::class),
            $dispatcher,
            $this->createMock(BackupCommandExecutor::class)
        );

        $service->createCheckItem($settings);

        static::assertSame(['checked', 'saved', 'event'], $sequence);
        static::assertSame([[$repository, 'no errors were found']], $events);
    }

    public function testSnapshotsDeletedEventIsDispatchedAfterBatchRemoval(): void
    {
        $repositoryId = Uuid::randomHex();
        $repository = new BackupRepositoryEntity();
        $repository->setId($repositoryId);
        $repository->setRepositoryPath('/tmp/repository');
        $repository->setRepositoryPassword('secret');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with($repositoryId)->willReturn($repository);

        $result = new ResultEntity();
        $result->setOutput('removed');
        $manageClient = $this->createMock(Manage::class);
        $manageClient->expects(static::exactly(2))->method('removeSnapshotById')->willReturn($result);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(AfterSnapshotsDeletedEvent::NAME, static function (AfterSnapshotsDeletedEvent $event) use (&$events): void {
            $events[] = [
                $event->getBackupRepository(),
                $event->getSnapshotIds(),
                $event->getOutput(),
            ];
        });

        $service = $this->createManageRepositoryService($backupRepository, $dispatcher, $manageClient);
        $snapshotIds = ['a1b2c3d4', 'e5f6g7h8'];

        static::assertSame("removed\nremoved", $service->removeSnapshotByIds($repositoryId, $snapshotIds));
        static::assertSame([[$repository, $snapshotIds, "removed\nremoved"]], $events);
    }

    public function testRepositoryCleanedUpEventIsDispatchedAfterPrune(): void
    {
        $repositoryId = Uuid::randomHex();
        $repository = new BackupRepositoryEntity();
        $repository->setId($repositoryId);
        $repository->setRepositoryPath('/tmp/repository');
        $repository->setRepositoryPassword('secret');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with($repositoryId)->willReturn($repository);

        $result = new ResultEntity();
        $result->setOutput('repository cleaned');
        $manageClient = $this->createMock(Manage::class);
        $manageClient->expects(static::once())->method('executePrune')->willReturn($result);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(AfterRepositoryCleanedUpEvent::NAME, static function (AfterRepositoryCleanedUpEvent $event) use (&$events): void {
            $events[] = [$event->getBackupRepository(), $event->getOutput()];
        });

        $service = $this->createManageRepositoryService($backupRepository, $dispatcher, $manageClient);

        static::assertSame('repository cleaned', $service->cleanupRepository($repositoryId));
        static::assertSame([[$repository, 'repository cleaned']], $events);
    }

    public function testSnapshotsForgottenEventIsDispatchedAfterForget(): void
    {
        $repositoryId = Uuid::randomHex();
        $repository = new BackupRepositoryEntity();
        $repository->setId($repositoryId);
        $repository->setRepositoryPath('/tmp/repository');
        $repository->setRepositoryPassword('secret');
        $repository->setForgetDaily(7);
        $repository->setForgetWeekly(4);
        $repository->setForgetMonthly(12);
        $repository->setForgetYearly(3);
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with($repositoryId)->willReturn($repository);

        $result = new ResultEntity();
        $result->setOutput('snapshots forgotten');
        $manageClient = $this->createMock(Manage::class);
        $manageClient->expects(static::once())->method('removeSnapshots')->willReturn($result);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(AfterSnapshotsForgottenEvent::NAME, static function (AfterSnapshotsForgottenEvent $event) use (&$events): void {
            $events[] = [$event->getBackupRepository(), $event->getOutput()];
        });

        $service = $this->createManageRepositoryService($backupRepository, $dispatcher, $manageClient);

        static::assertSame('snapshots forgotten', $service->forgetSnapshots($repositoryId));
        static::assertSame([[$repository, 'snapshots forgotten']], $events);
    }

    public function testSnapshotDeletedEventIsDispatchedAfterSuccessfulRemoval(): void
    {
        $repositoryId = Uuid::randomHex();
        $snapshotId = 'a1b2c3d4';
        $output = '{"message":"removed"}';

        $repository = new BackupRepositoryEntity();
        $repository->setId($repositoryId);
        $repository->setRepositoryPath('/tmp/repository');
        $repository->setRepositoryPassword('secret');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with($repositoryId)->willReturn($repository);

        $result = new ResultEntity();
        $result->setOutput($output);
        $manageClient = $this->createMock(Manage::class);
        $manageClient->expects(static::once())->method('removeSnapshotById')->willReturn($result);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(AfterSnapshotDeletedEvent::NAME, static function (AfterSnapshotDeletedEvent $event) use (&$events): void {
            $events[] = [$event->getBackupRepositoryId(), $event->getSnapshotId(), $event->getOutput()];
        });

        $service = $this->createManageRepositoryService($backupRepository, $dispatcher, $manageClient);

        static::assertSame($output, $service->removeSnapshotById($repositoryId, $snapshotId));
        static::assertSame([[$repositoryId, $snapshotId, $output]], $events);
    }

    private function createManageRepositoryService(
        BackupRepository $backupRepository,
        EventDispatcher $eventDispatcher,
        Manage $manageClient
    ): ManageRepository {
        return new class(
            $this->createMock(LoggerInterface::class),
            $backupRepository,
            $this->createMock(BackupFileSnapshotsRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(Helper::class),
            $eventDispatcher,
            $manageClient
        ) extends ManageRepository {
            public function __construct(
                LoggerInterface $logger,
                BackupRepository $backupRepository,
                BackupFileSnapshotsRepository $backupFileSnapshotsRepository,
                BackupRepositoryChecks $backupRepositoryChecks,
                SettingsInterface $pluginSettings,
                Helper $pluginHelper,
                EventDispatcher $eventDispatcher,
                private readonly Manage $manageClient
            ) {
                parent::__construct(
                    $logger,
                    $backupRepository,
                    $backupFileSnapshotsRepository,
                    $backupRepositoryChecks,
                    $pluginSettings,
                    $pluginHelper,
                    $eventDispatcher
                );
            }

            protected function createManageClient(): Manage
            {
                return $this->manageClient;
            }
        };
    }
}
