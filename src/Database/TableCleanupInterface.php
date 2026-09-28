<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Database;

use Symfony\Component\Console\Output\OutputInterface;

interface TableCleanupInterface
{
    public function getTempTableName(): string;
    public function getCreateTableStatement(): string;
    public function checkOldTempTable(): bool;
    public function removeOldTableItems(): void;
    public function createTempTable(string $sqlCreateStatement): bool;
    public function countTableItems(string $tableName): int;
    public function removeTableByName(string $tableName): void;
    public function copyTableItems(string $sourceTableName, string $targetTableName): void;

    /**
     * Removes $oldTableName if it still exists from a previous, aborted run.
     */
    public function checkOldSwapTable(string $oldTableName): bool;

    /**
     * Atomically replaces $tableName with $tempTableName via RENAME TABLE.
     */
    public function swapWithTempTable(string $tableName, string $tempTableName, string $oldTableName): void;
}
