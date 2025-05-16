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
namespace MuckiFacilityPlugin\Database\TableRunner;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;

class LogEntryCleanupRunner implements TableCleanupInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $pluginSettings
    ) {}

    public function getCreateTableStatement(OutputInterface $cliOutput): string
    {
        return '';
    }

    public function checkOldTempTable(OutputInterface $cliOutput): void
    {
        // TODO: Implement checkOldTempTable() method.
    }

    public function removeOldTableItems(OutputInterface $cliOutput): void
    {
        // TODO: Implement removeOldTableItems() method.
    }

    public function createTempTable(string $sqlCreateStatement, OutputInterface $cliOutput): bool
    {
        return false;
    }

    public function copyTableItemsIntoTempTable(OutputInterface $cliOutput): void
    {
        // TODO: Implement copyTableItemsIntoTempTable() method.
    }

    public function countTableItemsInTempTable(OutputInterface $cliOutput): ?int
    {
        return null;
    }

    public function removeTableByName(string $tableName, OutputInterface $cliOutput): void
    {
        // TODO: Implement removeTableByName() method.
    }

    public function createNewTable(string $sqlCreateStatement, OutputInterface $cliOutput): void
    {
        // TODO: Implement createNewTable() method.
    }

    public function insertCartItemsFromTempTable(OutputInterface $cliOutput): void
    {
        // TODO: Implement insertCartItemsFromTempTable() method.
    }
}
