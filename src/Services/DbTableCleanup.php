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

    public function cleanupTable(string $tableName): bool
    {
        $runner = $this->getCleanupRunner($tableName);
        $sqlCreateStatement = $this->prepareCleanup($runner);

        if($sqlCreateStatement) {

            $this->performCleanup($runner, $sqlCreateStatement);
            $runner->removeTableByName($tableName);
        } else {

            $this->logger->error('No SQL create statement found for table: '.$tableName, PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return false;
        }

        return true;
    }

    public function prepareCleanup(TableCleanupInterface $runner): ?string
    {
        try {

            $sqlCreateStatement = $runner->getCreateTableStatement();
            $runner->checkOldTempTable();
            $runner->removeOldTableItems();
            $runner->createTempTable($sqlCreateStatement);

        } catch (\Exception $e) {

            $this->logger->error('Error during prepare cleanup: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return null;
        }

        return $sqlCreateStatement;
    }

    public function performCleanup(TableCleanupInterface $runner, string $sqlCreateStatement): bool
    {
        try {

            $runner->copyTableItemsIntoTempTable();

            if($runner->countTableItemsInTempTable() >= 1) {
                $runner->removeTableByName($runner->getTempTableName());
                $runner->createNewTable($sqlCreateStatement);
                $runner->insertCartItemsFromTempTable();
            } else {
                $this->logger->info('Found no items', PluginDefaults::DEFAULT_LOGGER_CONFIG);
            }

        } catch (\Exception $e) {

            $this->logger->error('Error during prepare cleanup: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
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
