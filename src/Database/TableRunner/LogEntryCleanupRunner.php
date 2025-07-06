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
use Doctrine\DBAL\Exception;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\CliOutput;

class LogEntryCleanupRunner extends CleanupRunner implements TableCleanupInterface
{
    const LOG_ENTRY_TEMP_TABLE_NAME = 'log_entry_temp';

    protected string $logEntryTempTableName = self::LOG_ENTRY_TEMP_TABLE_NAME;

    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected SettingsInterface $pluginSettings,
        protected CliOutput $cliOutput,
        protected DatabaseHelper $databaseHelper
    ) {
        parent::__construct($logger, $connection, $pluginSettings, $cliOutput, $databaseHelper);
    }

    public function getLogEntryTempTableName(): string
    {
        return $this->logEntryTempTableName;
    }

    public function setCartTempTableName(string $logEntryTempTableName): void
    {
        $this->logEntryTempTableName = $logEntryTempTableName;
    }

    public function getCreateTableStatement(): string
    {
        $this->cliOutput->writeNewLineCliOutput('Get create log_entry SQL statement');

        try {
            $sql = 'SHOW CREATE TABLE log_entry';
            $originLogEntryCreateStatement = $this->connection->executeQuery($sql)->fetchAllKeyValue()['log_entry'];
        } catch (Exception $e) {

            $this->logger->error(print_r($e, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Not possible to insert log_entry items into origin table '.$e->getMessage());
        }

        return str_replace(
            "\n",
            '',
            $originLogEntryCreateStatement
        );
    }

    public function checkOldTempTable(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tableName = $this->getLogEntryTempTableName();

        try {

            if ($schemaManager->tablesExist($tableName)) {

                $this->cliOutput->writeNewLineCliOutput('Drop old '.$tableName.' table');
                $schemaManager->dropTable($tableName);
            }

        } catch (Exception $e) {

            $this->logger->error(print_r($e->getMessage(), true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Not possible to check old '.$tableName.' table');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');

        return true;
    }

    public function removeOldTableItems(): void
    {
        $this->cliOutput->writeNewLineCliOutput('Remove old log entry items');
        $lastValidDate = $this->pluginSettings->getLastValidDateForLogEntry();

        if($this->databaseHelper->columnExists('log_entry', 'updated_at')) {
            $sql = '
            DELETE FROM
                `log_entry`
            WHERE
		            created_at <= ' . $this->connection->quote($lastValidDate) . '
	            AND
		            (updated_at IS NULL OR updated_at <= ' . $this->connection->quote($lastValidDate) . ')
        ';
        } else {
            $sql = '
            DELETE FROM
                `cart`
            WHERE
		            created_at <= ' . $this->connection->quote($lastValidDate) . '
        ';
        }

        try {
            $this->connection->executeStatement($sql);
        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->cliOutput->writeNewLineCliOutput($e->getMessage());
            throw new Exception('Problem to remove old log entry items');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');
    }

    public function createTempTable(string $sqlCreateStatement): bool
    {
        $this->cliOutput->writeNewLineCliOutput('Create temp log_entry table');

        $sqlCart = str_replace(
            'log_entry',
            $this::LOG_ENTRY_TEMP_TABLE_NAME,
            $sqlCreateStatement
        );

        $sql = str_replace(
            'CREATE TABLE',
            'CREATE TABLE IF NOT EXISTS',
            $sqlCart
        );

        try {
            $this->connection->executeStatement($sql);
            $this->cliOutput->writeSameLineCliOutput('...done');

        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Problem to create temp log_entry table');
        }

        return true;
    }

    public function countTableItems(string $tableName): int
    {
        $this->cliOutput->writeNewLineCliOutput('Check log_entry items in temp table');

        $sql = '
            SELECT * FROM `'.$tableName.'`;
        ';

        try {
            $counter = $this->connection->executeQuery($sql)->rowCount();
        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('copy of log_entry items into temp table not possible');
        }

        $this->cliOutput->writeSameLineCliOutput('...found ' . $counter . ' current log_entry items');

        return $counter;
    }

    public function removeTableByName(string $tableName): void
    {
        $this->cliOutput->writeNewLineCliOutput('Remove '.$tableName.' table');

        try {

            $this->connection->executeStatement('DROP TABLE `'.$tableName.'`;');
            $this->cliOutput->writeSameLineCliOutput('...done');

        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Not possible to remove '.$tableName.' table');
        }
    }

    public function createNewTable(string $sqlCreateStatement): void
    {
        $this->cliOutput->writeNewLineCliOutput('Create new log_entry table');

        try {

            $this->connection->executeStatement($sqlCreateStatement);
            $this->cliOutput->writeSameLineCliOutput('...done');

        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Not possible to create new log_entry table');
        }
    }

    public function getTempTableName(): string
    {
        return 'log_entry_temp';
    }
}
