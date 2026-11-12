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
namespace MuckiFacilityPlugin\Backup\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Exceptions\CannotSetParameter;
use Spatie\DbDumper\Exceptions\CannotStartDump;
use Spatie\DbDumper\Exceptions\DumpFailed;

use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;

/**
 * Dumper for MySQL / MariaDB based on spatie/db-dumper (mysqldump).
 *
 * Default connection (databaseUrl set): exactly the legacy behaviour — setDatabaseUrl(DATABASE_URL),
 * single transaction, table list from the Shopware system connection.
 * Own connection: host/port/name/user/password are passed one by one; spatie hands the password to
 * mysqldump through a temporary credentials file, so it never shows up in the process arguments.
 * Table list and connection test use a short-lived DBAL connection that is closed afterwards.
 */
class MysqlDatabaseDumper implements DatabaseDumperInterface
{
    protected const MASK = '***';

    public function __construct(
        protected CoreDatabase $database
    )
    {}

    public function listTables(ResolvedDatabaseConnection $connection): array
    {
        if ($connection->getDatabaseUrl() !== null) {
            return array_values($this->database->getListOfAllTables() ?? []);
        }

        return $this->withConnection(
            $connection,
            'Cannot list tables:',
            static fn (Connection $dbal): array => array_values(array_map('strval', $dbal->fetchFirstColumn('SHOW TABLES')))
        );
    }

    public function testConnection(ResolvedDatabaseConnection $connection): void
    {
        $this->withConnection(
            $connection,
            'Connection failed:',
            static fn (Connection $dbal): mixed => $dbal->executeQuery('SELECT 1')->fetchOne()
        );
    }

    public function dump(ResolvedDatabaseConnection $connection, string $dumpFile, array $tables = []): void
    {
        try {
            $mysqlDumper = $this->createDumper();
            $this->configureDumper($mysqlDumper, $connection);
            $mysqlDumper->useSingleTransaction();

            if ($tables !== []) {
                $mysqlDumper->includeTables($tables);
            }

            $mysqlDumper->dumpToFile($dumpFile);
        } catch (CannotStartDump $e) {
            throw $this->createException('Cannot start dump:' . $e->getMessage(), $connection);
        } catch (DumpFailed $e) {
            throw $this->createException('Dump failed:' . $e->getMessage(), $connection);
        } catch (CannotSetParameter $e) {
            throw $this->createException('Cannot set parameter:' . $e->getMessage(), $connection);
        } catch (\Throwable $e) {
            // Default connection: anything else keeps propagating unchanged, as before.
            if ($connection->getDatabaseUrl() !== null) {
                throw $e;
            }
            throw $this->createException('Dump failed:' . $e->getMessage(), $connection);
        }
    }

    protected function createDumper(): MySql
    {
        return MySql::create();
    }

    protected function createConnection(ResolvedDatabaseConnection $connection): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $connection->getHost(),
            'port' => $connection->getPort(),
            'dbname' => $connection->getDatabaseName(),
            'user' => $connection->getUsername(),
            'password' => $connection->getPassword(),
        ]);
    }

    protected function configureDumper(MySql $mysqlDumper, ResolvedDatabaseConnection $connection): void
    {
        $databaseUrl = $connection->getDatabaseUrl();
        if ($databaseUrl !== null) {
            $mysqlDumper->setDatabaseUrl($databaseUrl);
            return;
        }

        $mysqlDumper->setHost($connection->getHost());
        if ($connection->getPort() !== null) {
            $mysqlDumper->setPort($connection->getPort());
        }
        $mysqlDumper->setDbName($connection->getDatabaseName());
        $mysqlDumper->setUserName($connection->getUsername());
        $mysqlDumper->setPassword($connection->getPassword());
    }

    /**
     * @template T
     * @param callable(Connection): T $callback
     * @return T
     * @throws DatabaseBackupFailedException
     */
    protected function withConnection(ResolvedDatabaseConnection $connection, string $prefix, callable $callback): mixed
    {
        $dbal = null;
        try {
            $dbal = $this->createConnection($connection);

            return $callback($dbal);
        } catch (\Throwable $e) {
            throw $this->createException($prefix . ' ' . $e->getMessage(), $connection);
        } finally {
            $dbal?->close();
        }
    }

    /**
     * The original exception is not chained on purpose: its message may contain the password.
     */
    protected function createException(string $message, ResolvedDatabaseConnection $connection): DatabaseBackupFailedException
    {
        return new DatabaseBackupFailedException($this->maskPassword($message, $connection));
    }

    protected function maskPassword(string $message, ResolvedDatabaseConnection $connection): string
    {
        $password = $connection->getPassword();
        if ($password === '') {
            return $message;
        }

        return str_replace(
            array_unique([$password, rawurlencode($password), urlencode($password)]),
            self::MASK,
            $message
        );
    }
}
