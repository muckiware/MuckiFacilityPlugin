<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Database\TableRunner;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;

use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Database\TableRunner\CartCleanupRunner;
use MuckiFacilityPlugin\Database\TableRunner\LogEntryCleanupRunner;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\Services\SettingsInterface;

/**
 * Covers a corruption that swapWithTempTable() (see CleanupRunnerTest) turned from harmless
 * into permanent: createTempTable() used to rename the temp table via a blanket
 * str_replace('cart', 'cart_temp', $sqlCreateStatement) — which also rewrites the substring
 * "cart" inside index and constraint names, e.g. `idx.cart.created_at` becomes
 * `idx.cart_temp.created_at`.
 *
 * While the temp table was only ever a throwaway copy, that renamed index name never mattered.
 * Now that the temp table is swapped in to become the live table, the corrupted name would
 * become permanent — and cart_temp itself contains the substring "cart", so the same
 * replacement would corrupt it further on every subsequent cleanup run
 * (`idx.cart_temp.created_at` -> `idx.cart_temp_temp.created_at` -> ...).
 *
 * These tests capture the SQL handed to Connection::executeStatement() without touching a
 * database, so they run under phpunit.unit.xml.
 */
class TempTableNamingTest extends TestCase
{
    public function testCartTempTableOnlyRenamesTheTableNotIndexNames(): void
    {
        $createStatement = 'CREATE TABLE `cart` (`token` varchar(50) NOT NULL,'
            .'`created_at` datetime(3) NOT NULL,PRIMARY KEY (`token`),'
            .'KEY `idx.cart.created_at` (`created_at`)) ENGINE=InnoDB';

        $executedSql = $this->captureExecutedSql(
            fn (Connection $connection) => $this->createCartRunner($connection)->createTempTable($createStatement)
        );

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `cart_temp`', $executedSql);
        self::assertStringContainsString('KEY `idx.cart.created_at`', $executedSql);
        self::assertStringNotContainsString('idx.cart_temp.created_at', $executedSql);
    }

    public function testLogEntryTempTableOnlyRenamesTheTableNotIndexOrConstraintNames(): void
    {
        $createStatement = 'CREATE TABLE `log_entry` (`id` binary(16) NOT NULL,'
            .'`context` json DEFAULT NULL,`created_at` datetime(3) NOT NULL,PRIMARY KEY (`id`),'
            .'KEY `idx.log_entry.created_at` (`created_at`),'
            .'CONSTRAINT `json.log_entry.context` CHECK (json_valid(`context`))) ENGINE=InnoDB';

        $executedSql = $this->captureExecutedSql(
            fn (Connection $connection) => $this->createLogEntryRunner($connection)->createTempTable($createStatement)
        );

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `log_entry_temp`', $executedSql);
        self::assertStringContainsString('KEY `idx.log_entry.created_at`', $executedSql);
        self::assertStringContainsString('CONSTRAINT `json.log_entry.context`', $executedSql);
        self::assertStringNotContainsString('log_entry_temp.created_at', $executedSql);
        self::assertStringNotContainsString('json.log_entry_temp.context', $executedSql);
    }

    private function captureExecutedSql(\Closure $call): string
    {
        $executedSql = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql = $sql;
                return 0;
            });

        $call($connection);

        self::assertIsString($executedSql, 'executeStatement() was never called');

        return $executedSql;
    }

    private function createCartRunner(Connection $connection): CartCleanupRunner
    {
        return new CartCleanupRunner(
            $this->createMock(LoggerInterface::class),
            $connection,
            $this->createMock(SettingsInterface::class),
            $this->createMock(CliOutput::class),
            $this->createMock(DatabaseHelper::class)
        );
    }

    private function createLogEntryRunner(Connection $connection): LogEntryCleanupRunner
    {
        return new LogEntryCleanupRunner(
            $this->createMock(LoggerInterface::class),
            $connection,
            $this->createMock(SettingsInterface::class),
            $this->createMock(CliOutput::class),
            $this->createMock(DatabaseHelper::class)
        );
    }
}
