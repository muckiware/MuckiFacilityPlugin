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
namespace MuckiFacilityPlugin\Subscriber;

use Spatie\DbDumper\DsnParser;
use Spatie\DbDumper\Exceptions\InvalidDatabaseUrl;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use MuckiFacilityPlugin\Backup\Database\MysqlDatabaseDumper;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Core\DatabaseTypes;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Events\ResolveDatabaseConnectionEvent;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Exception\UnresolvableDatabasePasswordException;
use MuckiFacilityPlugin\Services\Content\DatabaseConnectionRepository;
use MuckiFacilityPlugin\Services\Content\DatabasePasswordResolver;
use MuckiFacilityPlugin\Services\SettingsInterface;

/**
 * Built-in handler for the `mysql` type (MySQL / MariaDB).
 *
 * Only fills what an earlier (higher priority) listener has not set yet.
 */
class MysqlDatabaseConnectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected SettingsInterface $pluginSettings,
        protected DatabasePasswordResolver $passwordResolver,
        protected MysqlDatabaseDumper $dumper
    )
    {}

    public static function getSubscribedEvents(): array
    {
        return [
            ResolveDatabaseConnectionEvent::NAME => ['onResolveDatabaseConnection', 0],
        ];
    }

    /**
     * @throws DatabaseBackupFailedException
     */
    public function onResolveDatabaseConnection(ResolveDatabaseConnectionEvent $event): void
    {
        if ($event->getType() !== DatabaseTypes::MYSQL) {
            return;
        }

        if ($event->getResolvedConnection() === null) {
            $databaseConnection = $event->getDatabaseConnection();
            $event->setResolvedConnection(
                $databaseConnection === null
                    ? $this->resolveDefaultConnection()
                    : $this->resolveOwnConnection($databaseConnection)
            );
        }

        if ($event->getDumper() === null) {
            $event->setDumper($this->dumper);
        }
    }

    /**
     * Connection data from DATABASE_URL. The dumper uses the URL itself (legacy behaviour); the
     * single fields are best effort, an unparsable URL fails in the dumper exactly as before.
     */
    protected function resolveDefaultConnection(): ResolvedDatabaseConnection
    {
        $databaseUrl = $this->pluginSettings->getDatabaseUrl();

        try {
            $parts = (new DsnParser($databaseUrl))->parse();
        } catch (InvalidDatabaseUrl) {
            $parts = [];
        }

        return new ResolvedDatabaseConnection(
            DatabaseTypes::MYSQL,
            (string) ($parts['host'] ?? ''),
            isset($parts['port']) ? (int) $parts['port'] : null,
            (string) ($parts['database'] ?? ''),
            (string) ($parts['username'] ?? ''),
            (string) ($parts['password'] ?? ''),
            [],
            $databaseUrl
        );
    }

    /**
     * @throws DatabaseBackupFailedException
     */
    protected function resolveOwnConnection(DatabaseConnectionEntity $databaseConnection): ResolvedDatabaseConnection
    {
        $connectionName = $databaseConnection->getName();
        $this->assertNoForbiddenCharacters($databaseConnection);

        $passwordSource = PasswordSource::tryFrom($databaseConnection->getPasswordSource());
        if ($passwordSource === null) {
            throw new DatabaseBackupFailedException(sprintf(
                'Database connection "%s": unknown password source "%s"',
                $connectionName,
                $databaseConnection->getPasswordSource()
            ));
        }

        try {
            $password = $this->passwordResolver->resolve($databaseConnection->getPassword(), $passwordSource);
        } catch (UnresolvableDatabasePasswordException $e) {
            throw new DatabaseBackupFailedException(sprintf(
                'Database connection "%s": password could not be resolved: %s',
                $connectionName,
                $e->getMessage()
            ), 0, $e);
        }

        return new ResolvedDatabaseConnection(
            DatabaseTypes::MYSQL,
            $databaseConnection->getHost(),
            $databaseConnection->getPort() ?? DatabaseTypes::DEFAULT_PORTS[DatabaseTypes::MYSQL],
            $databaseConnection->getDatabaseName(),
            $databaseConnection->getUsername(),
            $password
        );
    }

    /**
     * Defence in depth for rows not written through DatabaseConnectionRepository: spatie writes
     * host and user unescaped into the mysqldump credentials file. The message names only the field.
     *
     * @throws DatabaseBackupFailedException
     */
    protected function assertNoForbiddenCharacters(DatabaseConnectionEntity $databaseConnection): void
    {
        $fields = [
            'host' => $databaseConnection->getHost(),
            'username' => $databaseConnection->getUsername(),
            'databaseName' => $databaseConnection->getDatabaseName(),
        ];
        foreach ($fields as $field => $value) {
            if (DatabaseConnectionRepository::containsForbiddenCharacter($value)) {
                throw new DatabaseBackupFailedException(sprintf(
                    'Database connection "%s": field "%s" must not contain line breaks or NUL bytes',
                    $databaseConnection->getName(),
                    $field
                ));
            }
        }
    }
}
