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
namespace MuckiFacilityPlugin\Services;

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\Settings as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Database\TableCleanupRunnerFactory;
use MuckiFacilityPlugin\Database\TableCleanupInterface;

class DbTableCleanup
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
        protected TableCleanupRunnerFactory $tableCleanupRunnerFactory
    ) {}

    public function cleanupTable(string $tableNameForCleanup): bool
    {
        $runner = $this->getCleanupRunner($tableNameForCleanup);
        if($runner->countTableItems($tableNameForCleanup) < 1) {

            $this->logger->info('No items found in table: '.$tableNameForCleanup, PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return true;
        }

        if(!$this->prepareCleanup($runner)) {

            $this->logger->error('Cleanup could not be prepared for table: '.$tableNameForCleanup, PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return false;
        }

        return $this->performCleanup($runner, $tableNameForCleanup);
    }

    public function prepareCleanup(TableCleanupInterface $runner): bool
    {
        try {

            $sqlCreateStatement = $runner->getCreateTableStatement();
            $runner->checkOldTempTable();
            $runner->createTempTable($sqlCreateStatement);

            $runner->removeOldTableItems();

        } catch (\Exception $e) {

            $this->logger->error('Error during prepare cleanup: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return false;
        }

        return true;
    }

    /**
     * Copies the remaining rows into a temp table, then swaps it in for the live table.
     */
    public function performCleanup(TableCleanupInterface $runner, string $tableNameForCleanup): bool
    {
        $tempTableName = $runner->getTempTableName();

        try {

            $runner->copyTableItems($tableNameForCleanup, $tempTableName);

            if($runner->countTableItems($tempTableName) >= 1) {

                $oldTableName = $tableNameForCleanup.'_old';
                $runner->checkOldSwapTable($oldTableName);
                $runner->swapWithTempTable($tableNameForCleanup, $tempTableName, $oldTableName);
                $runner->removeTableByName($oldTableName);
            } else {

                $this->logger->info('Found no items', PluginDefaults::DEFAULT_LOGGER_CONFIG);
                $runner->removeTableByName($tempTableName);
            }

        } catch (\Exception $e) {

            $this->logger->error('Error during perform cleanup: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return false;
        }

        return true;
    }

    public function getCleanupRunner(string $cleanupTableName): ?TableCleanupInterface
    {
        try {
            return $this->tableCleanupRunnerFactory->createTableCleanupRunner($cleanupTableName);

        } catch (\Exception $e) {
            $this->logger->error('Error during table cleanup: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }
}
