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
namespace MuckiFacilityPlugin\Events;

use Shopware\Core\Framework\Context;
use Symfony\Contracts\EventDispatcher\Event;

use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;

/**
 * Dispatched before every database dump and every connection test.
 *
 * Subscribers set the resolved connection data and the dumper for the given type. The built-in
 * MysqlDatabaseConnectionSubscriber (priority 0) handles `mysql` and only fills what is still
 * missing, so a listener with a higher priority can provide its own connection (e.g. a password
 * from a vault) and/or dumper. stopPropagation() is allowed.
 */
class ResolveDatabaseConnectionEvent extends Event
{
    public const NAME = 'mucki_facility.database_connection.resolve';

    protected ?ResolvedDatabaseConnection $resolvedConnection = null;

    protected ?DatabaseDumperInterface $dumper = null;

    public function __construct(
        protected string $type,
        protected ?DatabaseConnectionEntity $databaseConnection,
        protected ?BackupRepositorySettings $backupSettings,
        protected Context $context
    )
    {}

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Null means the default connection (DATABASE_URL).
     */
    public function getDatabaseConnection(): ?DatabaseConnectionEntity
    {
        return $this->databaseConnection;
    }

    /**
     * Null for a connection test.
     */
    public function getBackupSettings(): ?BackupRepositorySettings
    {
        return $this->backupSettings;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function isDefaultConnection(): bool
    {
        return $this->databaseConnection === null;
    }

    public function setResolvedConnection(ResolvedDatabaseConnection $resolvedConnection): void
    {
        $this->resolvedConnection = $resolvedConnection;
    }

    public function getResolvedConnection(): ?ResolvedDatabaseConnection
    {
        return $this->resolvedConnection;
    }

    public function setDumper(DatabaseDumperInterface $dumper): void
    {
        $this->dumper = $dumper;
    }

    public function getDumper(): ?DatabaseDumperInterface
    {
        return $this->dumper;
    }

    public function isResolved(): bool
    {
        return $this->resolvedConnection !== null && $this->dumper !== null;
    }
}
