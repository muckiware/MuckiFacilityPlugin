<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\MessageQueue\Handler;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\MessageQueue\Handler\RestoreSnapshotHandler;
use MuckiFacilityPlugin\MessageQueue\Message\RestoreSnapshotMessage;
use MuckiFacilityPlugin\Services\AdminNotification;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\RestoreSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;

final class RestoreSnapshotHandlerTest extends TestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $notifications = [];

    public function testNotifiesAdministrationWhenRestoreIsDone(): void
    {
        $message = $this->createMessage();
        $restoreSnapshot = $this->createMock(RestoreSnapshot::class);
        $restoreSnapshot->expects(self::once())->method('restoreSnapshot')->with($message, false);

        $this->createHandler($restoreSnapshot)($message);

        self::assertCount(1, $this->notifications);
        self::assertSame('info', $this->notifications[0]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.restoreSucceeded',
            'parameters' => ['name' => 'Daily backup', 'snapshot' => '0123abcd'],
        ], json_decode($this->notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->notifications[0]['id']);
        self::assertTrue($this->notifications[0]['adminOnly']);
        self::assertSame(['muwa_backup_repository:restore'], $this->notifications[0]['requiredPrivileges']);
    }

    public function testNotifiesAdministrationAndRethrowsWhenRestoreFails(): void
    {
        $restoreSnapshot = $this->createMock(RestoreSnapshot::class);
        $restoreSnapshot->method('restoreSnapshot')->willThrowException(new \RuntimeException('restic failed'));

        try {
            $this->createHandler($restoreSnapshot)($this->createMessage());
            self::fail('Restore failure must be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('restic failed', $exception->getMessage());
        }

        self::assertCount(1, $this->notifications);
        self::assertSame('error', $this->notifications[0]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.restoreFailed',
            'parameters' => ['name' => 'Daily backup', 'snapshot' => '0123abcd', 'error' => 'restic failed'],
        ], json_decode($this->notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR));
    }

    private function createHandler(RestoreSnapshot $restoreSnapshot): RestoreSnapshotHandler
    {
        $repository = $this->createMock(BackupRepositoryEntity::class);
        $repository->method('getName')->willReturn('Daily backup');
        $repository->method('getRepositoryPassword')->willReturn('secret');
        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->with('repository-id')->willReturn($repository);

        $notificationService = $this->createMock(AdminNotification::class);
        $notificationService->method('createNotification')
            ->willReturnCallback(function (array $data, Context $context): void {
                $this->notifications[] = $data;
            });

        return new RestoreSnapshotHandler(
            new NullLogger(),
            $restoreSnapshot,
            $this->createMock(CliOutput::class),
            $backupRepository,
            $notificationService
        );
    }

    private function createMessage(): RestoreSnapshotMessage
    {
        $message = new RestoreSnapshotMessage();
        $message->setBackupRepositoryId('repository-id');
        $message->setSnapshotId('0123abcdef0123abcdef');

        return $message;
    }
}
