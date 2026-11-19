<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use PHPUnit\Framework\TestCase;

final class ScheduleCalculatorTest extends TestCase
{
    public function testCalculatesNextOccurrenceAndReturnsUtc(): void
    {
        $next = (new ScheduleCalculator())->next('0 9 * * *', 'Europe/Berlin', new \DateTimeImmutable('2026-01-10 08:30:00 UTC'));
        self::assertSame('2026-01-11 08:00:00', $next->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $next->getTimezone()->getName());
    }

    public function testHandlesDstTransitionInConfiguredTimezone(): void
    {
        $next = (new ScheduleCalculator())->next('0 9 * * *', 'Europe/Berlin', new \DateTimeImmutable('2026-03-28 12:00:00 UTC'));
        self::assertSame('2026-03-29 07:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testRejectsInvalidCronExpression(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScheduleCalculator())->next('not a cron', 'UTC', new \DateTimeImmutable());
    }

    public function testRejectsInvalidTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScheduleCalculator())->next('* * * * *', 'Mars/Olympus', new \DateTimeImmutable());
    }

    public function testCalculatesConsecutiveRunsInUtc(): void
    {
        $runs = (new ScheduleCalculator())->nextRuns('0 9 * * *', 'Europe/Berlin', 3, new \DateTimeImmutable('2026-03-27 12:00:00 UTC'));
        self::assertSame(
            ['2026-03-28 08:00:00', '2026-03-29 07:00:00', '2026-03-30 07:00:00'],
            array_map(static fn (\DateTimeImmutable $run): string => $run->format('Y-m-d H:i:s'), $runs)
        );
        self::assertSame('UTC', $runs[0]->getTimezone()->getName());
    }

    public function testNextRunsRejectsInvalidCronExpression(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScheduleCalculator())->nextRuns('not a cron', 'UTC', 3);
    }
}
