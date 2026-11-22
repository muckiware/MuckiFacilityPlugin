<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\MessageQueue\Handler\CreateBackupHandler;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryInactiveException;
use PHPUnit\Framework\TestCase;

final class CreateBackupHandlerTest extends TestCase
{
    public function testInactiveRepositoryIsNotRedelivered(): void
    {
        $execution = $this->createMock(BackupExecution::class);
        $execution->expects(self::once())->method('execute')->willThrowException(new BackupRepositoryInactiveException());

        // must not throw, otherwise the messenger retries the message
        (new CreateBackupHandler($execution))(new CreateBackupMessage('bb'));
    }

    public function testOtherFailuresAreStillThrown(): void
    {
        $execution = $this->createMock(BackupExecution::class);
        $execution->method('execute')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        (new CreateBackupHandler($execution))(new CreateBackupMessage('bb'));
    }
}
