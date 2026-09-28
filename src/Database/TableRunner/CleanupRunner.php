<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Database\TableRunner;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Exception\TableCleanupFailedException;
use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\CliOutput;

class CleanupRunner
{
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected SettingsInterface $pluginSettings,
        protected CliOutput $cliOutput,
        protected DatabaseHelper $databaseHelper
    )
    {}

    public function copyTableItems(string $sourceTableName, string $targetTableName): void
    {
        $this->cliOutput->writeNewLineCliOutput('Copy '.$sourceTableName.' items into '.$targetTableName);

        $sql = '
            INSERT INTO `' . $targetTableName . '`
            SELECT * FROM `' . $sourceTableName . '`;
        ';

        try {
            $this->connection->executeStatement($sql);
        } catch (Exception $e) {

            $this->logger->error(print_r($e, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new TableCleanupFailedException('copy of '.$sourceTableName.' items into '.$targetTableName.' table not possible');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');
    }

    /**
     * @throws TableCleanupFailedException
     */
    public function checkOldSwapTable(string $oldTableName): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        try {

            if ($schemaManager->tablesExist([$oldTableName])) {

                $this->cliOutput->writeNewLineCliOutput('Drop leftover '.$oldTableName.' table');
                $schemaManager->dropTable($oldTableName);
            }

        } catch (Exception $e) {

            $this->logger->error(print_r($e->getMessage(), true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new TableCleanupFailedException('Not possible to check old '.$oldTableName.' table');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');

        return true;
    }

    /**
     * @throws TableCleanupFailedException
     */
    public function swapWithTempTable(string $tableName, string $tempTableName, string $oldTableName): void
    {
        $this->cliOutput->writeNewLineCliOutput('Swap '.$tableName.' with '.$tempTableName);

        $sql = 'RENAME TABLE `'.$tableName.'` TO `'.$oldTableName.'`, `'.$tempTableName.'` TO `'.$tableName.'`;';

        try {
            $this->connection->executeStatement($sql);
        } catch (Exception $e) {

            $this->logger->error(print_r($e, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new TableCleanupFailedException('swap of '.$tableName.' with '.$tempTableName.' not possible');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');
    }
}
