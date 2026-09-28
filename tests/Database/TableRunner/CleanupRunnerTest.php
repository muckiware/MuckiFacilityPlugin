<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Database\TableRunner;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Database\TableRunner\CleanupRunner;
use MuckiFacilityPlugin\Exception\TableCleanupFailedException;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\Services\SettingsInterface;

/**
 * Covers the atomic table swap introduced to fix the DROP TABLE / CREATE TABLE window in
 * DbTableCleanup: between dropping the live table and recreating it, a crash used to leave the
 * table missing entirely. RENAME TABLE with two pairs is a single statement, so there is no such
 * window. These tests run against a mocked Connection — no database needed.
 */
class CleanupRunnerTest extends TestCase
{
    public function testSwapWithTempTableExecutesASingleAtomicRename(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('RENAME TABLE `cart` TO `cart_old`, `cart_temp` TO `cart`;');

        $runner = $this->createRunner($connection);
        $runner->swapWithTempTable('cart', 'cart_temp', 'cart_old');
    }

    public function testSwapWithTempTableWrapsDbalFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(
            $this->createMock(DbalException::class)
        );

        $runner = $this->createRunner($connection);

        $this->expectException(TableCleanupFailedException::class);
        $runner->swapWithTempTable('cart', 'cart_temp', 'cart_old');
    }

    public function testCheckOldSwapTableDoesNothingWhenNoLeftoverExists(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->with(['cart_old'])->willReturn(false);
        $schemaManager->expects(self::never())->method('dropTable');

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $runner = $this->createRunner($connection);

        self::assertTrue($runner->checkOldSwapTable('cart_old'));
    }

    public function testCheckOldSwapTableDropsALeftoverFromAnAbortedRun(): void
    {
        // Simulates a run that got killed between swapWithTempTable() and the following
        // removeTableByName() — cart_old is still there and would make the next
        // RENAME TABLE fail with "table already exists" if left in place.
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->with(['cart_old'])->willReturn(true);
        $schemaManager->expects(self::once())->method('dropTable')->with('cart_old');

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $runner = $this->createRunner($connection);

        self::assertTrue($runner->checkOldSwapTable('cart_old'));
    }

    public function testCheckOldSwapTableWrapsSchemaManagerFailure(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willThrowException(
            $this->createMock(DbalException::class)
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $runner = $this->createRunner($connection);

        $this->expectException(TableCleanupFailedException::class);
        $runner->checkOldSwapTable('cart_old');
    }

    private function createRunner(Connection $connection): CleanupRunner
    {
        $logger = $this->createMock(LoggerInterface::class);
        $settings = $this->createMock(SettingsInterface::class);
        $cliOutput = $this->createMock(CliOutput::class);
        $databaseHelper = $this->createMock(DatabaseHelper::class);

        return new CleanupRunner($logger, $connection, $settings, $cliOutput, $databaseHelper);
    }
}
