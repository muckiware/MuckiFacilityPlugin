<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Services\Backup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class BackupExecutionTest extends TestCase
{
    public function testLoadsCurrentConfigurationAndExecutesBackupUnderGlobalLock(): void
    {
        $settings = new \MuckiFacilityPlugin\Entity\BackupRepositorySettings();
        $backup = $this->createMock(Backup::class);
        $backup->expects(self::once())->method('prepareCreateBackup')->with('repository-id')->willReturn($settings);
        $backup->expects(self::once())->method('createBackup')->with($settings, false);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())->method('createLock')->with('mucki-facility.backup', null)->willReturn($lock);

        (new BackupExecution($backup, $lockFactory))->execute('repository-id');
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

        $this->expectException(BackupAlreadyRunningException::class);
        (new BackupExecution($backup, $lockFactory))->execute('repository-id');
    }

    public function testFailureIsObservableAndAlwaysReleasesGlobalLock(): void
    {
        $backup = $this->createMock(Backup::class);
        $backup->method('prepareCreateBackup')->willThrowException(new \RuntimeException('failed'));

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $this->expectExceptionMessage('failed');
        (new BackupExecution($backup, $lockFactory))->execute('repository-id');
    }
}
