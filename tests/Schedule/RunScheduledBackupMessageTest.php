<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Schedule\Message\RunScheduledBackupMessage;
use PHPUnit\Framework\TestCase;

final class RunScheduledBackupMessageTest extends TestCase
{
    public function testScheduledMessageContainsOnlyImmutableIdentifiers(): void
    {
        $message = new RunScheduledBackupMessage('schedule-id', 'repository-id', 'run-token');
        self::assertSame('schedule-id', $message->scheduleId);
        self::assertSame('repository-id', $message->repositoryId);
        self::assertSame('run-token', $message->runToken);
        self::assertCount(3, (new \ReflectionClass($message))->getProperties());
    }

    public function testManualMessageContainsRepositoryIdOnly(): void
    {
        $message = new CreateBackupMessage('repository-id');
        self::assertSame('repository-id', $message->repositoryId);
        self::assertCount(1, (new \ReflectionClass($message))->getProperties());
    }
}
