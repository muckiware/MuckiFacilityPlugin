<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use MuckiFacilityPlugin\Schedule\ScheduleConfiguration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

final class ScheduleConfigurationTest extends TestCase
{
    public function testEnableUpsertsScheduledStateAtNextOccurrence(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('INSERT INTO `muwa_backup_schedule`'),
            self::callback(fn(array $p): bool => $p['enabled'] === 1 && $p['status'] === 'scheduled' && $p['nextExecutionTime'] !== null && $p['runToken'] === null)
        );
        $service = new ScheduleConfiguration($connection, new ScheduleCalculator());
        $service->configure(Uuid::randomHex(), true, '*/5 * * * *', 'UTC', new \DateTimeImmutable('2026-01-01 00:01:00 UTC'));
    }

    public function testDisableClearsNextExecutionAndRunToken(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('INSERT INTO `muwa_backup_schedule`'),
            self::callback(fn(array $p): bool => $p['enabled'] === 0 && $p['status'] === 'inactive' && $p['nextExecutionTime'] === null && $p['runToken'] === null)
        );
        (new ScheduleConfiguration($connection, new ScheduleCalculator()))->configure(Uuid::randomHex(), false, '0 1 * * *', 'UTC');
    }

    public function testRejectsUnknownRepository(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryNotFoundException::class);
        (new ScheduleConfiguration($connection, new ScheduleCalculator()))
            ->configure(Uuid::randomHex(), true, '*/5 * * * *', 'UTC');
    }
}
