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
namespace MuckiFacilityPlugin\Entity;

use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;

/**
 * Result of DatabaseConnectionResolver: what to dump and with which dumper.
 */
class DatabaseBackupTarget
{
    public function __construct(
        protected ResolvedDatabaseConnection $connection,
        protected DatabaseDumperInterface $dumper,
        protected bool $isDefault,
        protected string $connectionName
    )
    {}

    public function getConnection(): ResolvedDatabaseConnection
    {
        return $this->connection;
    }

    public function getDumper(): DatabaseDumperInterface
    {
        return $this->dumper;
    }

    /**
     * True for the default connection (DATABASE_URL, no database connection assigned).
     */
    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }
}
