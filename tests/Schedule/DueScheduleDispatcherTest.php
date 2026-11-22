<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\DueScheduleDispatcher;
use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\SettingsInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DueScheduleDispatcherTest extends TestCase
{
    public function testClaimsAndDispatchesDueScheduleOnce(): void
    {
        $row = ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'repository_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'cron_expression' => '* * * * *', 'timezone' => 'UTC', 'repository_active' => 1];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([$row]);
        $db->expects(self::once())->method('executeStatement')->with(self::stringContains("`status` = 'queued'"))->willReturn(1);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(fn(object $m): Envelope => new Envelope($m));
        (new DueScheduleDispatcher($db, $bus, new ScheduleCalculator(), new Helper(), $this->settings(true)))->dispatch(new \DateTimeImmutable('2026-01-01 00:00:30 UTC'));
    }

    public function testTerminalPreviousRunRemainsEligibleForItsNextOccurrence(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->with(self::callback(fn(string $sql): bool => str_contains($sql, "'success'") && str_contains($sql, "'failed'") && str_contains($sql, "'skipped'")))->willReturn([]);
        (new DueScheduleDispatcher($db, $this->createMock(MessageBusInterface::class), new ScheduleCalculator(), new Helper(), $this->settings(true)))->dispatch();
    }

    public function testLosingAtomicClaimDoesNotDispatchDuplicate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([['id'=>'aa','repository_id'=>'bb','cron_expression'=>'* * * * *','timezone'=>'UTC','repository_active'=>1]]);
        $db->method('executeStatement')->willReturn(0);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new DueScheduleDispatcher($db, $bus, new ScheduleCalculator(), new Helper(), $this->settings(true)))->dispatch();
    }

    public function testInactiveRepositoryIsSkippedAndNotDispatched(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([['id'=>'aa','repository_id'=>'bb','cron_expression'=>'0 2 * * *','timezone'=>'UTC','repository_active'=>0]]);
        $db->expects(self::once())->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                self::assertStringContainsString("`status` = 'skipped'", $sql);
                self::assertStringNotContainsString('run_token', $sql);
                self::assertSame(DueScheduleDispatcher::INACTIVE_REPOSITORY_ERROR, $params['error']);
                // the due run is moved on, a reactivated repository does not catch it up immediately
                self::assertSame('2026-01-02 02:00:00.000', $params['next']);
                return 1;
            }
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new DueScheduleDispatcher($db, $bus, new ScheduleCalculator(), new Helper(), $this->settings(true)))->dispatch(new \DateTimeImmutable('2026-01-01 02:00:30 UTC'));
    }

    public function testSelectReadsRepositoryActiveFlag(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->with(self::callback(fn(string $sql): bool => str_contains($sql, 'muwa_backup_repository') && str_contains($sql, 'repository_active')))->willReturn([]);
        (new DueScheduleDispatcher($db, $this->createMock(MessageBusInterface::class), new ScheduleCalculator(), new Helper(), $this->settings(true)))->dispatch();
    }

    public function testInactivePluginSkipsEveryDueScheduleWithoutDispatch(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([['id'=>'aa','repository_id'=>'bb','cron_expression'=>'0 2 * * *','timezone'=>'UTC','repository_active'=>1]]);
        $db->expects(self::once())->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                self::assertStringContainsString("`status` = 'skipped'", $sql);
                self::assertSame(DueScheduleDispatcher::INACTIVE_PLUGIN_ERROR, $params['error']);
                self::assertSame('2026-01-02 02:00:00.000', $params['next']);
                return 1;
            }
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new DueScheduleDispatcher($db, $bus, new ScheduleCalculator(), new Helper(), $this->settings(false)))->dispatch(new \DateTimeImmutable('2026-01-01 02:00:30 UTC'));
    }

    private function settings(bool $enabled): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('isEnabled')->willReturn($enabled);

        return $settings;
    }
}
