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

use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\CleanupTables;
use MuckiFacilityPlugin\Exception\InvalidTableCleanupException;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Database\TableRunner\CartCleanupRunner;
use MuckiFacilityPlugin\Database\TableRunner\LogEntryCleanupRunner;
use MuckiFacilityPlugin\Database\DatabaseHelper;

class TableCleanupRunnerFactory
{
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected SettingsInterface $settings,
        protected ServicesCliOutput $servicesCliOutput,
        protected DatabaseHelper $databaseHelper
    ) {}

    /**
     * @throws InvalidTableCleanupException
     */
    public function createTableCleanupRunner(string $cleanupTableName): TableCleanupInterface
    {
        switch ($cleanupTableName) {

            case CleanupTables::CART->value:
                $runner = new CartCleanupRunner(
                    $this->logger,
                    $this->connection,
                    $this->settings,
                    $this->servicesCliOutput,
                    $this->databaseHelper
                );
                break;

            case CleanupTables::LOG_ENTRY->value:
                $runner = new LogEntryCleanupRunner(
                    $this->logger,
                    $this->connection,
                    $this->settings,
                    $this->servicesCliOutput,
                    $this->databaseHelper
                );
                break;

            default:
                $this->logger->error('Invalid backup type:'.$cleanupTableName, PluginDefaults::DEFAULT_LOGGER_CONFIG);
                throw new InvalidTableCleanupException();
        }

        return $runner;
    }
}
