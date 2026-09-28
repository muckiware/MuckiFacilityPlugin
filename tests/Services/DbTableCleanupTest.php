<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Database\TableCleanupRunnerFactory;
use MuckiFacilityPlugin\Exception\TableCleanupFailedException;
use MuckiFacilityPlugin\Services\DbTableCleanup;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\Settings as PluginSettings;

/**
 * Covers performCleanup()'s branching after the DROP TABLE / CREATE TABLE swap was replaced
 * with an atomic RENAME TABLE (see CleanupRunnerTest for the RENAME statement itself).
 *
 * The regression this guards against: previously, cleanupTable() dropped the temp table
 * unconditionally after performCleanup() ran. Once the swap renames the temp table into the
 * live table's place, that temp table name no longer exists — an unconditional drop would
 * throw. The two branches below must each remove exactly the table that is actually still
 * there afterwards, and nothing else.
 */
class DbTableCleanupTest extends TestCase
{
    public function testPerformCleanupSwapsWhenTempTableHasRows(): void
    {
        $runner = $this->createMock(TableCleanupInterface::class);
        $runner->method('getTempTableName')->willReturn('cart_temp');
        $runner->method('countTableItems')->with('cart_temp')->willReturn(2);

        $runner->expects(self::once())->method('copyTableItems')->with('cart', 'cart_temp');
        $runner->expects(self::once())->method('checkOldSwapTable')->with('cart_old');
        $runner->expects(self::once())->method('swapWithTempTable')->with('cart', 'cart_temp', 'cart_old');
        // Only the displaced table is removed. cart_temp itself no longer exists — it was
        // renamed into cart's place — so removing it again would fail against a real database.
        $runner->expects(self::once())->method('removeTableByName')->with('cart_old');

        $dbTableCleanup = $this->createService();

        self::assertTrue($dbTableCleanup->performCleanup($runner, 'cart'));
    }

    public function testPerformCleanupDropsTempTableWhenItEndsUpEmpty(): void
    {
        // The initial DELETE in removeOldTableItems() already removed everything that was
        // in `cart` — copying zero remaining rows into the temp table leaves it empty. There
        // is nothing to swap in, so the live table is left untouched.
        $runner = $this->createMock(TableCleanupInterface::class);
        $runner->method('getTempTableName')->willReturn('cart_temp');
        $runner->method('countTableItems')->with('cart_temp')->willReturn(0);

        $runner->expects(self::never())->method('checkOldSwapTable');
        $runner->expects(self::never())->method('swapWithTempTable');
        $runner->expects(self::once())->method('removeTableByName')->with('cart_temp');

        $dbTableCleanup = $this->createService();

        self::assertTrue($dbTableCleanup->performCleanup($runner, 'cart'));
    }

    public function testPerformCleanupReturnsFalseWhenCopyFails(): void
    {
        $runner = $this->createMock(TableCleanupInterface::class);
        $runner->method('getTempTableName')->willReturn('cart_temp');
        $runner->method('copyTableItems')->willThrowException(
            new TableCleanupFailedException('copy failed')
        );

        $runner->expects(self::never())->method('swapWithTempTable');
        $runner->expects(self::never())->method('removeTableByName');

        $dbTableCleanup = $this->createService();

        self::assertFalse($dbTableCleanup->performCleanup($runner, 'cart'));
    }

    public function testPerformCleanupReturnsFalseWhenSwapFails(): void
    {
        $runner = $this->createMock(TableCleanupInterface::class);
        $runner->method('getTempTableName')->willReturn('cart_temp');
        $runner->method('countTableItems')->willReturn(2);
        $runner->method('swapWithTempTable')->willThrowException(
            new TableCleanupFailedException('swap failed')
        );

        // A failed swap must not fall through to removing the temp table — swapWithTempTable()
        // may have already renamed it away.
        $runner->expects(self::never())->method('removeTableByName');

        $dbTableCleanup = $this->createService();

        self::assertFalse($dbTableCleanup->performCleanup($runner, 'cart'));
    }

    private function createService(): DbTableCleanup
    {
        return new DbTableCleanup(
            $this->createMock(LoggerInterface::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(TableCleanupRunnerFactory::class)
        );
    }
}
