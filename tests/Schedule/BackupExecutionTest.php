<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Services\Backup;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Notification\NotificationService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class BackupExecutionTest extends TestCase
{
    public function testLoadsCurrentConfigurationAndExecutesBackupUnderGlobalLock(): void
    {
        $settings = new \MuckiFacilityPlugin\Entity\BackupRepositorySettings();
        $settings->setName('Daily backup');
        $backup = $this->createMock(Backup::class);
        $backup->expects(self::once())->method('prepareCreateBackup')->with('repository-id')->willReturn($settings);
        $backup->expects(self::once())->method('createBackup')->with($settings, false);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())->method('createLock')->with('mucki-facility.backup', null)->willReturn($lock);

        $notifications = [];
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::exactly(2))
            ->method('createNotification')
            ->willReturnCallback(static function (array $data, Context $context) use (&$notifications): void {
                $notifications[] = $data;
            });

        (new BackupExecution($backup, $lockFactory, $notificationService))->execute('repository-id');

        self::assertCount(2, $notifications);
        self::assertSame('success', $notifications[0]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.backupStarted',
            'parameters' => ['name' => 'Daily backup'],
        ], json_decode($notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('info', $notifications[1]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.backupSucceeded',
            'parameters' => ['name' => 'Daily backup'],
        ], json_decode($notifications[1]['message'], true, 512, JSON_THROW_ON_ERROR));
        foreach ($notifications as $notification) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $notification['id']);
            self::assertTrue($notification['adminOnly']);
            self::assertSame(['muwa_backup_repository:backup'], $notification['requiredPrivileges']);
        }
    }

    public function testLockContentionStopsEveryExecutionPathBeforePreparingBackup(): void
    {
        $backup = $this->createMock(Backup::class);
        $backup->expects(self::never())->method('prepareCreateBackup');
        $backup->expects(self::never())->method('createBackup');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->with(false)->willReturn(false);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->with('mucki-facility.backup', null)->willReturn($lock);
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('createNotification');

        $this->expectException(BackupAlreadyRunningException::class);
        (new BackupExecution($backup, $lockFactory, $notificationService))->execute('repository-id');
    }

    public function testFailureIsObservableAndAlwaysReleasesGlobalLock(): void
    {
        $settings = new \MuckiFacilityPlugin\Entity\BackupRepositorySettings();
        $settings->setName('Daily backup');
        $backup = $this->createMock(Backup::class);
        $backup->method('prepareCreateBackup')->willReturn($settings);
        $backup->method('createBackup')->willThrowException(new \RuntimeException('failed'));

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $notifications = [];
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::exactly(2))
            ->method('createNotification')
            ->willReturnCallback(static function (array $data, Context $context) use (&$notifications): void {
                $notifications[] = $data;
            });

        try {
            (new BackupExecution($backup, $lockFactory, $notificationService))->execute('repository-id');
            self::fail('The backup exception was not rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }

        self::assertCount(2, $notifications);
        self::assertSame('success', $notifications[0]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.backupStarted',
            'parameters' => ['name' => 'Daily backup'],
        ], json_decode($notifications[0]['message'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('error', $notifications[1]['status']);
        self::assertSame([
            'source' => 'mucki-facility',
            'snippet' => 'muwa-backup-repository.notification.backupFailed',
            'parameters' => ['name' => 'Daily backup', 'error' => 'failed'],
        ], json_decode($notifications[1]['message'], true, 512, JSON_THROW_ON_ERROR));
    }
}
