<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\MessageQueue\Handler;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\MessageQueue\Handler\TakeoverRepositoryCheckHandler;
use MuckiFacilityPlugin\MessageQueue\Message\TakeoverRepositoryCheckMessage;
use MuckiFacilityPlugin\Services\AdminNotification;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\ManageRepository;
use MuckiFacilityPlugin\Services\RepositoryStats;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

final class TakeoverRepositoryCheckHandlerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $notifications = [];

    /** @var array<int, string> */
    private array $calls = [];

    public function testCheckRunsBeforeStats(): void
    {
        $manage = $this->createMock(ManageRepository::class);
        $manage->expects(self::once())->method('checkRepositoryStructure')->with('repository-id')
            ->willReturnCallback(function (): string {
                $this->calls[] = 'check';
                return 'no errors were found';
            });
        $stats = $this->createMock(RepositoryStats::class);
        $stats->expects(self::once())->method('collectAndSave')->with('repository-id')
            ->willReturnCallback(function (): ?array {
                $this->calls[] = 'stats';
                return ['totalSize' => null, 'totalFileCount' => null, 'snapshotsCount' => null, 'fileSystemSize' => null, 'checkStatus' => null];
            });

        $this->createHandler($manage, $stats, $this->createMock(LoggerInterface::class))($this->createMessage());

        self::assertSame(['check', 'stats'], $this->calls);
        self::assertCount(1, $this->notifications);
        self::assertSame('success', $this->notifications[0]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.takeoverCheckSucceeded',
            'parameters' => ['name' => 'Taken over', 'status' => 'no errors were found'],
        ], json_decode($this->notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->notifications[0]['id']);
        self::assertTrue($this->notifications[0]['adminOnly']);
        self::assertSame(['muwa_backup_repository:update'], $this->notifications[0]['requiredPrivileges']);
    }

    public function testCheckFailureIsLoggedStatsStillRunAndErrorNotified(): void
    {
        $manage = $this->createMock(ManageRepository::class);
        $manage->method('checkRepositoryStructure')->willThrowException(new \RuntimeException('check broke'));
        $stats = $this->createMock(RepositoryStats::class);
        $stats->expects(self::once())->method('collectAndSave')->willReturn(['totalSize' => null, 'totalFileCount' => null, 'snapshotsCount' => null, 'fileSystemSize' => null, 'checkStatus' => null]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->createHandler($manage, $stats, $logger)($this->createMessage());

        self::assertCount(1, $this->notifications);
        self::assertSame('error', $this->notifications[0]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.takeoverCheckFailed',
            'parameters' => ['name' => 'Taken over', 'error' => 'check broke'],
        ], json_decode($this->notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['muwa_backup_repository:update'], $this->notifications[0]['requiredPrivileges']);
    }

    public function testSwallowedStatsFailureIsNotifiedAsError(): void
    {
        $manage = $this->createMock(ManageRepository::class);
        $manage->method('checkRepositoryStructure')->willReturn('no errors were found');
        $stats = $this->createMock(RepositoryStats::class);
        $stats->method('collectAndSave')->willReturn(null);

        $this->createHandler($manage, $stats, $this->createMock(LoggerInterface::class))($this->createMessage());

        self::assertCount(1, $this->notifications);
        self::assertSame('error', $this->notifications[0]['status']);
        $decoded = json_decode($this->notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('muwa-backup-repository.notification.takeoverCheckFailed', $decoded['snippet']);
        self::assertSame(['name' => 'Taken over', 'error' => 'Repository statistics could not be collected'], $decoded['parameters']);
    }

    public function testThrowingStatsAreNotifiedAsError(): void
    {
        $manage = $this->createMock(ManageRepository::class);
        $manage->method('checkRepositoryStructure')->willReturn('no errors were found');
        $stats = $this->createMock(RepositoryStats::class);
        $stats->method('collectAndSave')->willThrowException(new \RuntimeException('stats broke'));

        $this->createHandler($manage, $stats, $this->createMock(LoggerInterface::class))($this->createMessage());

        self::assertCount(1, $this->notifications);
        self::assertSame('error', $this->notifications[0]['status']);
        $decoded = json_decode($this->notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('muwa-backup-repository.notification.takeoverCheckFailed', $decoded['snippet']);
        self::assertSame(['name' => 'Taken over', 'error' => 'stats broke'], $decoded['parameters']);
    }

    public function testMissingRepositoryEndsQuietly(): void
    {
        $manage = $this->createMock(ManageRepository::class);
        $manage->expects(self::never())->method('checkRepositoryStructure');
        $stats = $this->createMock(RepositoryStats::class);
        $stats->expects(self::never())->method('collectAndSave');

        $this->createHandler($manage, $stats, $this->createMock(LoggerInterface::class), false)($this->createMessage());

        self::assertSame([], $this->notifications);
    }

    private function createHandler(
        ManageRepository $manage,
        RepositoryStats $stats,
        LoggerInterface $logger,
        bool $repositoryExists = true
    ): TakeoverRepositoryCheckHandler {
        $entity = $this->createMock(BackupRepositoryEntity::class);
        $entity->method('getName')->willReturn('Taken over');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with('repository-id')
            ->willReturn($repositoryExists ? $entity : null);

        $notificationService = $this->createMock(AdminNotification::class);
        $notificationService->method('createNotification')
            ->willReturnCallback(function (array $data, Context $context): void {
                $this->notifications[] = $data;
            });

        return new TakeoverRepositoryCheckHandler($logger, $backupRepository, $manage, $stats, $notificationService);
    }

    private function createMessage(): TakeoverRepositoryCheckMessage
    {
        return new TakeoverRepositoryCheckMessage('repository-id');
    }
}
