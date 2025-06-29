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
use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\CliOutput;

class CartCleanupRunner implements TableCleanupInterface
{
    public const CART_TEMP_TABLE_NAME = 'cart_temp';
    protected string $cartTempTableName = self::CART_TEMP_TABLE_NAME;

    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected SettingsInterface $pluginSettings,
        protected CliOutput $cliOutput
    ) {}

    public function getCartTempTableName(): string
    {
        return $this->cartTempTableName;
    }

    public function setCartTempTableName(string $cartTempTableName): void
    {
        $this->cartTempTableName = $cartTempTableName;
    }

    public function getTempTableName(): string
    {
        return $this::CART_TEMP_TABLE_NAME;
    }

    /**
     * @throws Exception
     */
    public function getCreateTableStatement(): string
    {
        $this->cliOutput->writeNewLineCliOutput('Get create cart SQL statement');

        try {
            $sql = 'SHOW CREATE TABLE cart';
            $originCartCreateStatement = $this->connection->executeQuery($sql)->fetchAllKeyValue()['cart'];
        } catch (Exception $e) {

            $this->logger->error(print_r($e, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Not possible to insert cart items into origin table '.$e->getMessage());
        }

        return str_replace(
            "\n",
            '',
            $originCartCreateStatement
        );
    }

    /**
     * @throws Exception
     */
    public function checkOldTempTable(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tableName = $this->getCartTempTableName();

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

    /**
     * @throws Exception
     */
    public function removeOldTableItems(): void
    {
        $this->cliOutput->writeNewLineCliOutput('Remove old cart items');
        $lastValidDate = $this->pluginSettings->getLastValidDateForCart();

        $sql = '
            DELETE FROM
                `cart`
            WHERE
		            created_at <= ' . $this->connection->quote($lastValidDate) . '
	            AND
		            (updated_at IS NULL OR updated_at <= ' . $this->connection->quote($lastValidDate) . ')
        ';

        try {
            $this->connection->executeStatement($sql);
        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Problem to remove old cart items');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');
    }

    /**
     * @throws Exception
     */
    public function createTempTable(string $sqlCreateStatement): bool
    {
        $this->cliOutput->writeNewLineCliOutput('Create temp cart table');

        $sqlCart = str_replace(
            'cart',
            $this::CART_TEMP_TABLE_NAME,
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
            throw new Exception('Problem to create temp cart table');
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function countTableItems(string $tableName): int
    {
        $this->cliOutput->writeNewLineCliOutput('Check cart items in temp table');

        $sql = '
            SELECT * FROM `'.$tableName.'`;
        ';

        try {
            $counter = $this->connection->executeQuery($sql)->rowCount();
        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('copy of cart items into temp table not possible');
        }

        $this->cliOutput->writeSameLineCliOutput('...found ' . $counter . ' current cart items');

        return $counter;
    }

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
    public function createNewTable(string $sqlCreateStatement): void
    {
        $this->cliOutput->writeNewLineCliOutput('Create new cart table');

        try {

            $this->connection->executeStatement($sqlCreateStatement);
            $this->cliOutput->writeSameLineCliOutput('...done');

        } catch (Exception $e) {

            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('Not possible to create new cart table');
        }
    }

    public function copyTableItems(string $sourceTableName, string $targetTableName): void
    {
        $this->cliOutput->writeNewLineCliOutput('Copy cart items into temp table');

        $sql = '
            INSERT INTO `' . $targetTableName . '`
            SELECT * FROM `' . $sourceTableName . '`;
        ';

        try {
            $this->connection->executeStatement($sql);
        } catch (Exception $e) {

            $this->logger->error(print_r($e, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('copy of cart items into temp table not possible');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');
    }
}
