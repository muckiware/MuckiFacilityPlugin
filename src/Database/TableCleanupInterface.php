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
    public function copyTableItemsIntoTempTable(): void;
    public function countTableItemsInTempTable(): int;
    public function removeTableByName(string $tableName): void;
    public function createNewTable(string $sqlCreateStatement): void;
    public function insertCartItemsFromTempTable(): void;
}
