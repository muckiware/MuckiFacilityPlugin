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

use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;

/**
 * Creates database dumps for one database type. Set on the ResolveDatabaseConnectionEvent.
 *
 * Every error is thrown as DatabaseBackupFailedException with a message that never contains the password.
 */
interface DatabaseDumperInterface
{
    /**
     * Tables of the target database, used by CompleteFilesRunner.
     *
     * @return array<int, string>
     * @throws DatabaseBackupFailedException
     */
    public function listTables(ResolvedDatabaseConnection $connection): array;

    /**
     * Dumps the whole database, or only the given tables, into $dumpFile.
     *
     * @param array<int, string> $tables
     * @throws DatabaseBackupFailedException
     */
    public function dump(ResolvedDatabaseConnection $connection, string $dumpFile, array $tables = []): void;

    /**
     * Checks that the database is reachable with the given credentials.
     *
     * @throws DatabaseBackupFailedException with a user readable message
     */
    public function testConnection(ResolvedDatabaseConnection $connection): void;
}
