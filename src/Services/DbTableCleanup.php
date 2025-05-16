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
use Symfony\Component\Console\Output\OutputInterface;

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

    public function cleanupTable(string $tableName, OutputInterface $cliOutput = null): void
    {
        $runner = $this->getCleanupRunner($tableName);

        $sqlCreateStatement = $runner->getCreateTableStatement($cliOutput);
        $runner->checkOldTempTable($cliOutput);
        $runner->removeOldTableItems($cliOutput);

        if($runner->createTempTable($sqlCreateStatement, $cliOutput)) {

            $runner->copyTableItemsIntoTempTable($cliOutput);

            if($runner->countTableItemsInTempTable($cliOutput) >= 1) {
                $runner->removeTableByName($tableName, $cliOutput);
                $runner->createNewTable($sqlCreateStatement, $cliOutput);
                $runner->insertCartItemsFromTempTable($cliOutput);
            } else {
                $this->logger->info('Found no items', PluginDefaults::DEFAULT_LOGGER_CONFIG);
            }

            $runner->removeTableByName($tableName, $cliOutput);

        } else {
            $this->logger->error('Cancel cleanup', PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
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
