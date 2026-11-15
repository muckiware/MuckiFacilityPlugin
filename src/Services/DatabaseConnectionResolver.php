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
namespace MuckiFacilityPlugin\Services;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Core\DatabaseTypes;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Entity\DatabaseBackupTarget;
use MuckiFacilityPlugin\Events\ResolveDatabaseConnectionEvent;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;

/**
 * Resolves the database to dump (connection data + dumper) through ResolveDatabaseConnectionEvent.
 */
class DatabaseConnectionResolver
{
    public const DEFAULT_CONNECTION_NAME = 'default';

    /**
     * DATABASE_URL schemes that are handled as `mysql`.
     */
    protected const MYSQL_SCHEMES = ['mysql', 'mariadb', 'pdo-mysql', 'pdo_mysql', 'mysql2'];

    /**
     * @param EntityRepository<\MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionCollection> $databaseConnectionRepository
     */
    public function __construct(
        protected EventDispatcherInterface $eventDispatcher,
        protected EntityRepository $databaseConnectionRepository,
        protected SettingsInterface $pluginSettings
    )
    {}

    /**
     * Backups run in CLI/queue, so the default (system) context is used.
     *
     * @throws DatabaseBackupFailedException
     */
    public function resolveForBackup(BackupRepositorySettings $backupSettings): DatabaseBackupTarget
    {
        $context = Context::createDefaultContext();
        $connectionId = $backupSettings->getDatabaseConnectionId();
        $databaseConnection = $connectionId === null ? null : $this->loadConnection($connectionId, $context);

        return $this->dispatch($databaseConnection, $backupSettings, $context);
    }

    /**
     * Used by the connection test; null = default connection.
     *
     * @throws DatabaseBackupFailedException
     */
    public function resolveConnection(?DatabaseConnectionEntity $databaseConnection, Context $context): DatabaseBackupTarget
    {
        return $this->dispatch($databaseConnection, null, $context);
    }

    /**
     * @throws DatabaseBackupFailedException
     */
    protected function dispatch(
        ?DatabaseConnectionEntity $databaseConnection,
        ?BackupRepositorySettings $backupSettings,
        Context $context
    ): DatabaseBackupTarget {
        $type = $databaseConnection?->getType() ?? $this->getDefaultConnectionType();
        $connectionName = $databaseConnection?->getName() ?? self::DEFAULT_CONNECTION_NAME;

        $event = new ResolveDatabaseConnectionEvent($type, $databaseConnection, $backupSettings, $context);
        $this->eventDispatcher->dispatch($event, ResolveDatabaseConnectionEvent::NAME);

        $resolvedConnection = $event->getResolvedConnection();
        $dumper = $event->getDumper();
        if ($resolvedConnection === null || $dumper === null) {
            throw new DatabaseBackupFailedException(sprintf(
                'No database connection handler for type "%s" (connection "%s")',
                $type,
                $connectionName
            ));
        }

        return new DatabaseBackupTarget($resolvedConnection, $dumper, $databaseConnection === null, $connectionName);
    }

    /**
     * @throws DatabaseBackupFailedException
     */
    protected function loadConnection(string $connectionId, Context $context): DatabaseConnectionEntity
    {
        $databaseConnection = null;
        if (Uuid::isValid($connectionId)) {
            $databaseConnection = $context->scope(
                Context::SYSTEM_SCOPE,
                fn (Context $systemContext) => $this->databaseConnectionRepository
                    ->search(new Criteria([$connectionId]), $systemContext)
                    ->get($connectionId)
            );
        }

        if (!$databaseConnection instanceof DatabaseConnectionEntity) {
            throw new DatabaseBackupFailedException(sprintf('Database connection "%s" not found', $connectionId));
        }

        return $databaseConnection;
    }

    /**
     * Type of the default connection, derived from the scheme of DATABASE_URL.
     * Without a usable scheme it stays `mysql` (the only type Shopware itself supports).
     */
    protected function getDefaultConnectionType(): string
    {
        $scheme = parse_url($this->pluginSettings->getDatabaseUrl(), PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            return DatabaseTypes::MYSQL;
        }

        $scheme = strtolower($scheme);

        return in_array($scheme, self::MYSQL_SCHEMES, true) ? DatabaseTypes::MYSQL : $scheme;
    }
}
