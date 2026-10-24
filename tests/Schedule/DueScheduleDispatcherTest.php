<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\DueScheduleDispatcher;
use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DueScheduleDispatcherTest extends TestCase
{
    public function testClaimsAndDispatchesDueScheduleOnce(): void
    {
        $row = ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'repository_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'cron_expression' => '* * * * *', 'timezone' => 'UTC'];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([$row]);
        $db->expects(self::once())->method('executeStatement')->with(self::stringContains("`status` = 'queued'"))->willReturn(1);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(fn(object $m): Envelope => new Envelope($m));
        (new DueScheduleDispatcher($db, $bus, new ScheduleCalculator()))->dispatch(new \DateTimeImmutable('2026-01-01 00:00:30 UTC'));
    }

    public function testTerminalPreviousRunRemainsEligibleForItsNextOccurrence(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->with(self::callback(fn(string $sql): bool => str_contains($sql, "'success'") && str_contains($sql, "'failed'") && str_contains($sql, "'skipped'")))->willReturn([]);
        (new DueScheduleDispatcher($db, $this->createMock(MessageBusInterface::class), new ScheduleCalculator()))->dispatch();
    }

    public function testLosingAtomicClaimDoesNotDispatchDuplicate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([['id'=>'aa','repository_id'=>'bb','cron_expression'=>'* * * * *','timezone'=>'UTC']]);
        $db->method('executeStatement')->willReturn(0);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new DueScheduleDispatcher($db, $bus, new ScheduleCalculator()))->dispatch();
    }
}
