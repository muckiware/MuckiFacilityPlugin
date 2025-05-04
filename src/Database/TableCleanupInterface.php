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

interface TableCleanupInterface
{
    public function getCreateTableStatement(): string;
    public function checkOldTempTable(): void;
    public function removeOldTableItems(): void;
    public function createTempTable(): bool;
    public function copyTableItemsIntoTempTable(): void;
    public function countTableItemsInTempTable(): ?int;
    public function removeTableByName(): void;
    public function createNewTable(): void;
    public function insertCartItemsFromTempTable(): void;
}
