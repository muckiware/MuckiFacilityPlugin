<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Database\TableRunner;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;

use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Database\TableRunner\LogEntryCleanupRunner;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\Services\SettingsInterface;

/**
 * Covers a copy-paste bug in removeOldTableItems(): the branch taken when `log_entry` has no
 * `updated_at` column deleted from `cart` instead of `log_entry`, silently pruning the wrong
 * table on every cleanup run while log_entry itself never shrank.
 *
 * These tests capture the SQL handed to Connection::executeStatement() without touching a
 * database, so they run under phpunit.unit.xml.
 */
class LogEntryCleanupRunnerTest extends TestCase
{
    public function testRemoveOldTableItemsDeletesFromLogEntryWithoutUpdatedAtColumn(): void
    {
        $executedSql = $this->captureExecutedSql(hasUpdatedAtColumn: false);

        self::assertStringContainsString('DELETE FROM', $executedSql);
        self::assertStringContainsString('`log_entry`', $executedSql);
        self::assertStringNotContainsString('`cart`', $executedSql);
    }

    public function testRemoveOldTableItemsDeletesFromLogEntryWithUpdatedAtColumn(): void
    {
        $executedSql = $this->captureExecutedSql(hasUpdatedAtColumn: true);

        self::assertStringContainsString('DELETE FROM', $executedSql);
        self::assertStringContainsString('`log_entry`', $executedSql);
        self::assertStringNotContainsString('`cart`', $executedSql);
    }

    private function captureExecutedSql(bool $hasUpdatedAtColumn): string
    {
        $executedSql = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(fn (string $value): string => "'".$value."'");
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql = $sql;
                return 0;
            });

        $databaseHelper = $this->createMock(DatabaseHelper::class);
        $databaseHelper->method('columnExists')
            ->with('log_entry', 'updated_at')
            ->willReturn($hasUpdatedAtColumn);

        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getLastValidDateForLogEntry')->willReturn('2026-01-01 00:00:00');

        $runner = new LogEntryCleanupRunner(
            $this->createMock(LoggerInterface::class),
            $connection,
            $settings,
            $this->createMock(CliOutput::class),
            $databaseHelper
        );

        $runner->removeOldTableItems();

        self::assertIsString($executedSql, 'executeStatement() was never called');

        return $executedSql;
    }
}
