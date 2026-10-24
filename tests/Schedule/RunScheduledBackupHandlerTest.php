<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Schedule\Handler\RunScheduledBackupHandler;
use MuckiFacilityPlugin\Schedule\Message\RunScheduledBackupMessage;
use PHPUnit\Framework\TestCase;

final class RunScheduledBackupHandlerTest extends TestCase
{
    public function testRejectsStaleToken(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['run_token' => 'other', 'status' => 'queued']);
        $execution = $this->createMock(BackupExecution::class);
        $execution->expects(self::never())->method('execute');

        (new RunScheduledBackupHandler($db, $execution))(
            new RunScheduledBackupMessage('aa', 'bb', 'token')
        );
    }

    public function testGlobalLockContentionMarksRunSkipped(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['run_token' => 'token', 'status' => 'queued']);
        $db->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                if (str_contains($sql, "`status` = 'running'")) {
                    return 1;
                }
                self::assertSame('skipped', $params['status']);
                self::assertSame('Another backup is already running.', $params['error']);
                return 1;
            }
        );
        $execution = $this->createMock(BackupExecution::class);
        $execution->method('execute')->willThrowException(new BackupAlreadyRunningException());

        (new RunScheduledBackupHandler($db, $execution))(
            new RunScheduledBackupMessage('aa', 'bb', 'token')
        );
    }

    public function testFailureIsPersisted(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['run_token' => 'token', 'status' => 'queued']);
        $db->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                if (str_contains($sql, "`status` = 'running'")) {
                    return 1;
                }
                self::assertSame('failed', $params['status']);
                self::assertSame('boom', $params['error']);
                return 1;
            }
        );
        $execution = $this->createMock(BackupExecution::class);
        $execution->method('execute')->willThrowException(new \RuntimeException('boom'));

        (new RunScheduledBackupHandler($db, $execution))(
            new RunScheduledBackupMessage('aa', 'bb', 'token')
        );
    }

    public function testSuccessfulExecutionIsPersisted(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['run_token' => 'token', 'status' => 'queued']);
        $db->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                if (str_contains($sql, "`status` = 'running'")) {
                    return 1;
                }
                self::assertSame('success', $params['status']);
                self::assertNull($params['error']);
                return 1;
            }
        );
        $execution = $this->createMock(BackupExecution::class);
        $execution->expects(self::once())->method('execute')->with('bb');

        (new RunScheduledBackupHandler($db, $execution))(
            new RunScheduledBackupMessage('aa', 'bb', 'token')
        );
    }
}
