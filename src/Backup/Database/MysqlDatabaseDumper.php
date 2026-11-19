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
use MuckiFacilityPlugin\Core\Database\DbIdentifier;
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
 *
 * The dump is written into a hidden partial file and renamed to the target name only after
 * mysqldump succeeded, so a broken run never leaves a half dump that ends up in the backup.
 */
class MysqlDatabaseDumper implements DatabaseDumperInterface
{
    protected const MASK = '***';
    protected const PARTIAL_FILE_PREFIX = '.muwa-dump-';
    protected const PARTIAL_FILE_SUFFIX = '.partial';

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
            $this->assertValidIdentifiers($connection, $tables);
            $mysqlDumper = $this->createDumper();
            $this->configureDumper($mysqlDumper, $connection);
            $mysqlDumper->useSingleTransaction();
            // MySQL >= 8.0.21 requires the PROCESS privilege for tablespace info, which backup users often lack.
            $mysqlDumper->addExtraOption('--no-tablespaces');

            if ($tables !== []) {
                $mysqlDumper->includeTables($tables);
            }

            $this->runDump($mysqlDumper, $dumpFile);
        } catch (DatabaseBackupFailedException $e) {
            throw $e;
        } catch (CannotStartDump $e) {
            throw $this->createException('Cannot start dump:' . $e->getMessage(), $connection);
        } catch (DumpFailed $e) {
            throw $this->createException('Dump failed:' . $e->getMessage(), $connection);
        } catch (CannotSetParameter $e) {
            throw $this->createException('Cannot set parameter:' . $e->getMessage(), $connection);
        } catch (\InvalidArgumentException $e) {
            throw $this->createException('Invalid database identifier: ' . $e->getMessage(), $connection);
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

    /**
     * Replacement for MySql::dumpToFile(). spatie puts the output file into the shell command line
     * and only escapes \ and " in it, so $(...) and backticks of a configured dump path would be
     * executed. The command therefore only gets the generated partial file name (hex, no
     * metacharacters) and runs in the dump directory; the configured path never reaches the shell.
     *
     * @throws DatabaseBackupFailedException when the finished dump can not be moved to its target
     */
    protected function runDump(MySql $mysqlDumper, string $dumpFile): void
    {
        $directory = dirname($dumpFile);
        $partialFileName = self::PARTIAL_FILE_PREFIX . bin2hex(random_bytes(8)) . self::PARTIAL_FILE_SUFFIX;
        $partialFile = $directory . '/' . $partialFileName;
        $credentialsFileHandle = null;

        try {
            $mysqlDumper->guardAgainstIncompleteCredentials();
            $credentialsFileHandle = tmpfile();
            $mysqlDumper->setTempFileHandle($credentialsFileHandle);

            $process = $mysqlDumper->getProcess($partialFileName);
            $process->setWorkingDirectory($directory);
            $process->run();

            $mysqlDumper->checkIfDumpWasSuccessFul($process, $partialFile);
            if (!rename($partialFile, $dumpFile)) {
                throw new DatabaseBackupFailedException('Dump failed:Dump file could not be moved to ' . $dumpFile);
            }
        } finally {
            if (is_resource($credentialsFileHandle)) {
                // closing a tmpfile() handle deletes the credentials file right away
                fclose($credentialsFileHandle);
            }
            if (is_file($partialFile)) {
                unlink($partialFile);
            }
        }
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
     * spatie/db-dumper builds the mysqldump command as a shell string and inserts
     * the database name and the included table names unescaped (security finding
     * S-01). Rejects every value outside the safe identifier charset before it
     * can reach that sink; the database name of the default connection comes from
     * DATABASE_URL and is not user input, table names are validated in both cases.
     *
     * @param array<int, string> $tables
     */
    protected function assertValidIdentifiers(ResolvedDatabaseConnection $connection, array $tables): void
    {
        if ($connection->getDatabaseUrl() === null) {
            DbIdentifier::assertValid((string) $connection->getDatabaseName(), 'Database name');
        }

        foreach ($tables as $table) {
            DbIdentifier::assertValid((string) $table, 'Table name');
        }
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
