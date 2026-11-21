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
use MuckiFacilityPlugin\Events\BackupFinishedEvent;
use MuckiFacilityPlugin\Services\Backup;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcher;

class LifecycleEventDispatchTest extends TestCase
{
    public function testBackupLifecycleEventsAreDispatchedInOrder(): void
    {
        $repositoryId = Uuid::randomHex();
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId($repositoryId);
        $settings->setBackupType(BackupTypes::NONE_DATABASE->value);
        $path = new BackupPathEntity();
        $path->setBackupPath('/tmp/files');
        $path->setPosition(0);
        $path->setCompress(false);
        $settings->setBackupPaths([$path]);

        $result = new ResultEntity();
        $result->setOutput('backup created');
        $backupRunner = $this->createMock(BackupInterface::class);
        $backupRunner->method('getBackupResults')->willReturn([$result]);
        $checkRunner = $this->createMock(BackupInterface::class);
        $checkRunner->method('getBackupResults')->willReturn([]);
        $runnerFactory = $this->createMock(BackupRunnerFactory::class);
        $runnerFactory->method('createBackupRunner')->willReturnOnConsecutiveCalls($backupRunner, $checkRunner);

        $dispatcher = new EventDispatcher();
        $sequence = [];
        $finishedEvent = null;
        $dispatcher->addListener(BeforeBackupCreatedEvent::NAME, static function () use (&$sequence): void {
            $sequence[] = 'before';
        });
        $dispatcher->addListener(AfterBackupCreatedEvent::NAME, static function () use (&$sequence): void {
            $sequence[] = 'after';
        });
        $dispatcher->addListener(BackupFinishedEvent::NAME, static function (BackupFinishedEvent $event) use (&$sequence, &$finishedEvent): void {
            $sequence[] = 'finished';
            $finishedEvent = $event;
        });

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('saveBackupDuration')->willReturnCallback(
            static function () use (&$sequence): void {
                $sequence[] = 'duration';
            }
        );

        $service = new Backup(
            $this->createMock(LoggerInterface::class),
            $runnerFactory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(Helper::class),
            $this->createMock(ManageRepository::class),
            $this->createMock(CliOutput::class),
            $repositoryStats,
            $dispatcher,
            $this->createMock(MessageBusInterface::class)
        );

        $service->createBackup($settings, false);

        static::assertSame(['before', 'after', 'finished', 'duration'], $sequence);
        static::assertInstanceOf(BackupFinishedEvent::class, $finishedEvent);
        static::assertSame($settings, $finishedEvent->getBackupSettings());
        static::assertSame([$result], $finishedEvent->getResults());
        static::assertNull($finishedEvent->getPrimaryError());
        static::assertTrue($finishedEvent->isCoreSucceeded());
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
            $this->createMock(MessageBusInterface::class)
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
            ->willReturnCallback(static function () use (&$sequence): string {
                $sequence[] = 'saved';

                return 'cccccccccccccccccccccccccccccccc';
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
            $this->createMock(MessageBusInterface::class)
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

    public function testFinishedLifecycleRunsAfterBeforeFailure(): void
    {
        $this->assertPrimaryFailureLifecycle('before');
    }

    public function testFinishedLifecycleRunsAfterCoreFailure(): void
    {
        $this->assertPrimaryFailureLifecycle('core');
    }

    public function testFinishedLifecycleRunsAfterAfterFailure(): void
    {
        $this->assertPrimaryFailureLifecycle('after');
    }

    public function testFinishedFailureWinsOverDurationFailureAfterSuccessfulCore(): void
    {
        $settings = $this->createMinimalBackupSettings();
        $finishedError = new \RuntimeException('finished failure');
        $durationError = new \RuntimeException('duration failure');
        $durationCalls = 0;
        $finishedEvent = null;
        $loggedMessages = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            BackupFinishedEvent::NAME,
            static function (BackupFinishedEvent $event) use (&$finishedEvent, $finishedError): void {
                $finishedEvent = $event;
                throw $finishedError;
            }
        );

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('saveBackupDuration')->willReturnCallback(
            static function () use (&$durationCalls, $durationError): never {
                ++$durationCalls;
                throw $durationError;
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message) use (&$loggedMessages): void {
                $loggedMessages[] = $message;
            }
        );

        $service = $this->createMinimalBackupService($dispatcher, $repositoryStats, null, $logger);

        try {
            $service->createBackup($settings, false);
            static::fail('Expected the finished lifecycle failure');
        } catch (\Throwable $exception) {
            static::assertSame($finishedError, $exception);
        }

        static::assertInstanceOf(BackupFinishedEvent::class, $finishedEvent);
        static::assertNull($finishedEvent->getPrimaryError());
        static::assertTrue($finishedEvent->isCoreSucceeded());
        static::assertSame(1, $durationCalls);
        static::assertTrue($this->messagesContain($loggedMessages, 'duration failure'));
    }

    public function testPrimaryFailureWinsAndBothSuppressedLifecycleFailuresAreLogged(): void
    {
        $settings = $this->createMinimalBackupSettings();
        $primaryError = new \RuntimeException('primary failure');
        $finishedError = new \RuntimeException('finished failure');
        $durationError = new \RuntimeException('duration failure');
        $loggedMessages = [];
        $durationCalls = 0;

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BeforeBackupCreatedEvent::NAME, static function () use ($primaryError): never {
            throw $primaryError;
        });
        $dispatcher->addListener(BackupFinishedEvent::NAME, static function () use ($finishedError): never {
            throw $finishedError;
        });

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('saveBackupDuration')->willReturnCallback(
            static function () use (&$durationCalls, $durationError): never {
                ++$durationCalls;
                throw $durationError;
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message) use (&$loggedMessages): void {
                $loggedMessages[] = $message;
            }
        );

        $service = $this->createMinimalBackupService($dispatcher, $repositoryStats, null, $logger);

        try {
            $service->createBackup($settings, false);
            static::fail('Expected the primary backup failure');
        } catch (\Throwable $exception) {
            static::assertSame($primaryError, $exception);
        }

        static::assertSame(1, $durationCalls);
        static::assertTrue($this->messagesContain($loggedMessages, 'finished failure'));
        static::assertTrue($this->messagesContain($loggedMessages, 'duration failure'));
    }

    public function testDurationFailureIsThrownAfterSuccessfulLifecycle(): void
    {
        $settings = $this->createMinimalBackupSettings();
        $durationError = new \RuntimeException('duration failure');
        $dispatcher = new EventDispatcher();
        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('saveBackupDuration')->willThrowException($durationError);
        $service = $this->createMinimalBackupService($dispatcher, $repositoryStats);

        try {
            $service->createBackup($settings, false);
            static::fail('Expected the duration persistence failure');
        } catch (\Throwable $exception) {
            static::assertSame($durationError, $exception);
        }
    }

    private function assertPrimaryFailureLifecycle(string $failurePoint): void
    {
        $settings = $this->createMinimalBackupSettings();
        $primaryError = new \RuntimeException($failurePoint.' failure');
        $sequence = [];
        $finishedEvents = [];
        $durationCalls = 0;

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BeforeBackupCreatedEvent::NAME, static function () use (&$sequence, $failurePoint, $primaryError): void {
            $sequence[] = 'before';
            if ($failurePoint === 'before') {
                throw $primaryError;
            }
        });
        $dispatcher->addListener(AfterBackupCreatedEvent::NAME, static function () use (&$sequence, $failurePoint, $primaryError): void {
            $sequence[] = 'after';
            if ($failurePoint === 'after') {
                throw $primaryError;
            }
        });
        $dispatcher->addListener(BackupFinishedEvent::NAME, static function (BackupFinishedEvent $event) use (&$sequence, &$finishedEvents): void {
            $sequence[] = 'finished';
            $finishedEvents[] = $event;
        });

        $manage = $this->createMock(ManageRepository::class);
        $manage->method('saveSnapshots')->willReturnCallback(
            static function () use (&$sequence, $failurePoint, $primaryError): void {
                $sequence[] = 'core';
                if ($failurePoint === 'core') {
                    throw $primaryError;
                }
            }
        );
        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('saveBackupDuration')->willReturnCallback(
            static function () use (&$sequence, &$durationCalls): void {
                ++$durationCalls;
                $sequence[] = 'duration';
            }
        );

        $service = $this->createMinimalBackupService($dispatcher, $repositoryStats, $manage);

        try {
            $service->createBackup($settings, false);
            static::fail('Expected the '.$failurePoint.' failure');
        } catch (\Throwable $exception) {
            static::assertSame($primaryError, $exception);
        }

        $expectedSequence = match ($failurePoint) {
            'before' => ['before', 'finished', 'duration'],
            'core' => ['before', 'core', 'finished', 'duration'],
            'after' => ['before', 'core', 'after', 'finished', 'duration'],
            default => static::fail('Unexpected failure point'),
        };
        static::assertSame($expectedSequence, $sequence);
        static::assertSame(1, $durationCalls);
        static::assertCount(1, $finishedEvents);
        static::assertSame($settings, $finishedEvents[0]->getBackupSettings());
        static::assertSame($primaryError, $finishedEvents[0]->getPrimaryError());
        static::assertFalse($finishedEvents[0]->isCoreSucceeded());
    }

    private function createMinimalBackupSettings(): BackupRepositorySettings
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId(Uuid::randomHex());
        $settings->setBackupType(BackupTypes::NONE_DATABASE->value);
        $settings->setBackupPaths([]);

        return $settings;
    }

    private function createMinimalBackupService(
        EventDispatcher $dispatcher,
        RepositoryStats $repositoryStats,
        ?ManageRepository $manage = null,
        ?LoggerInterface $logger = null
    ): Backup {
        $runner = $this->createMock(BackupInterface::class);
        $runner->method('getBackupResults')->willReturn([]);
        $factory = $this->createMock(BackupRunnerFactory::class);
        $factory->method('createBackupRunner')->willReturn($runner);

        return new Backup(
            $logger ?? $this->createMock(LoggerInterface::class),
            $factory,
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(Helper::class),
            $manage ?? $this->createMock(ManageRepository::class),
            $this->createMock(CliOutput::class),
            $repositoryStats,
            $dispatcher,
            $this->createMock(MessageBusInterface::class)
        );
    }

    /**
     * @param array<string> $messages
     */
    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
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
