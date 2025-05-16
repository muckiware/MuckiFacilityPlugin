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
    public function getCreateTableStatement(OutputInterface $cliOutput): string;
    public function checkOldTempTable(OutputInterface $cliOutput): void;
    public function removeOldTableItems(OutputInterface $cliOutput): void;
    public function createTempTable(string $sqlCreateStatement, OutputInterface $cliOutput): bool;
    public function copyTableItemsIntoTempTable(OutputInterface $cliOutput): void;
    public function countTableItemsInTempTable(OutputInterface $cliOutput): ?int;
    public function removeTableByName(string $tableName, OutputInterface $cliOutput): void;
    public function createNewTable(string $sqlCreateStatement, OutputInterface $cliOutput): void;
    public function insertCartItemsFromTempTable(OutputInterface $cliOutput): void;
}
