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
use Doctrine\DBAL\Connection;

use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\CliOutput;

class LogEntryCleanupRunner implements TableCleanupInterface
{
    const LOG_ENTRY_TEMP_TABLE_NAME = 'log_entry_temp';

    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected SettingsInterface $pluginSettings,
        protected CliOutput $cliOutput
    ) {}

    public function getCreateTableStatement(): string
    {
        return '';
    }

    public function checkOldTempTable(): bool
    {
        return false;
    }

    public function removeOldTableItems(): void
    {
        // TODO: Implement removeOldTableItems() method.
    }

    public function createTempTable(string $sqlCreateStatement): bool
    {
        return false;
    }

    public function countTableItems(string $tableName): int
    {
        return 0;
    }

    public function removeTableByName(string $tableName): void
    {
        // TODO: Implement removeTableByName() method.
    }

    public function createNewTable(string $sqlCreateStatement): void
    {
        // TODO: Implement createNewTable() method.
    }

    public function getTempTableName(): string
    {
        return 'log_entry_temp';
    }

    public function copyTableItems(string $sourceTableName, string $targetTableName): void
    {
        // TODO: Implement copyTableItems() method.
    }
}
