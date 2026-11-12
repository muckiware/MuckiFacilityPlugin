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
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Backup\Database\MysqlDatabaseDumper;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionCollection;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Events\ResolveDatabaseConnectionEvent;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Services\Content\DatabasePasswordResolver;
use MuckiFacilityPlugin\Services\DatabaseConnectionResolver;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Subscriber\MysqlDatabaseConnectionSubscriber;

class DatabaseConnectionResolverTest extends TestCase
{
    private const CONNECTION_ID = '0190e6f1b0a07e0b9a3c4d5e6f708192';

    public function testNullConnectionDispatchesEventWithMysqlTypeAndIsDefault(): void
    {
        $settings = new BackupRepositorySettings();
        $dispatchedEvent = null;

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(static::once())
            ->method('dispatch')
            ->with(static::isInstanceOf(ResolveDatabaseConnectionEvent::class), ResolveDatabaseConnectionEvent::NAME)
            ->willReturnCallback(function (ResolveDatabaseConnectionEvent $event) use (&$dispatchedEvent) {
                $dispatchedEvent = $event;
                $event->setResolvedConnection(
                    new \MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection('mysql', 'database', 3306, 'shopware', 'root', 'root')
                );
                $event->setDumper($this->createMock(DatabaseDumperInterface::class));

                return $event;
            });

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::never())->method('search');

        $target = $this->createResolver($dispatcher, $repository)->resolveForBackup($settings);

        static::assertInstanceOf(ResolveDatabaseConnectionEvent::class, $dispatchedEvent);
        static::assertSame('mysql', $dispatchedEvent->getType());
        static::assertTrue($dispatchedEvent->isDefaultConnection());
        static::assertNull($dispatchedEvent->getDatabaseConnection());
        static::assertSame($settings, $dispatchedEvent->getBackupSettings());
        static::assertTrue($target->isDefault());
        static::assertSame('default', $target->getConnectionName());
        static::assertSame('shopware', $target->getConnection()->getDatabaseName());
    }

    /**
     * @dataProvider databaseUrlSchemeProvider
     */
    public function testDefaultTypeIsDerivedFromDatabaseUrlScheme(string $databaseUrl): void
    {
        $dispatcher = new EventDispatcher();
        $types = [];
        $dispatcher->addListener(ResolveDatabaseConnectionEvent::NAME, function (ResolveDatabaseConnectionEvent $event) use (&$types): void {
            $types[] = $event->getType();
            $event->setResolvedConnection(
                new \MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection('mysql', 'h', 3306, 'd', 'u', 'p')
            );
            $event->setDumper($this->createMock(DatabaseDumperInterface::class));
        });

        $this->createResolver($dispatcher, $this->createMock(EntityRepository::class), $databaseUrl)
            ->resolveConnection(null, Context::createDefaultContext());

        static::assertSame(['mysql'], $types);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function databaseUrlSchemeProvider(): array
    {
        return [
            'mysql' => ['mysql://root:root@database:3306/shopware'],
            'mariadb' => ['mariadb://root:root@database:3306/shopware'],
            'pdo-mysql' => ['pdo-mysql://root:root@database:3306/shopware'],
        ];
    }

    public function testUnknownConnectionIdThrows(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setDatabaseConnectionId(self::CONNECTION_ID);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(static::never())->method('dispatch');

        $this->expectException(DatabaseBackupFailedException::class);
        $this->expectExceptionMessage(self::CONNECTION_ID);

        $this->createResolver($dispatcher, $this->createRepository(null))->resolveForBackup($settings);
    }

    public function testUnhandledTypeThrows(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setDatabaseConnectionId(self::CONNECTION_ID);

        $this->expectException(DatabaseBackupFailedException::class);
        $this->expectExceptionMessage('No database connection handler for type "postgres"');

        $this->createResolver(new EventDispatcher(), $this->createRepository($this->createEntity('postgres')))
            ->resolveForBackup($settings);
    }

    public function testOwnConnectionIsResolvedThroughMysqlSubscriber(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setDatabaseConnectionId(self::CONNECTION_ID);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->createMysqlSubscriber($mysqlDumper));

        $target = $this->createResolver($dispatcher, $this->createRepository($this->createEntity('mysql')))
            ->resolveForBackup($settings);

        static::assertFalse($target->isDefault());
        static::assertSame('Shop replica', $target->getConnectionName());
        static::assertSame($mysqlDumper, $target->getDumper());
        static::assertSame('db.example.org', $target->getConnection()->getHost());
        static::assertSame('s3cret', $target->getConnection()->getPassword());
    }

    public function testHigherPrioritySubscriberOverridesConnectionAndDumper(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setDatabaseConnectionId(self::CONNECTION_ID);

        $ownDumper = $this->createMock(DatabaseDumperInterface::class);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->createMysqlSubscriber($mysqlDumper));
        $dispatcher->addListener(
            ResolveDatabaseConnectionEvent::NAME,
            static function (ResolveDatabaseConnectionEvent $event) use ($ownDumper): void {
                $event->setDumper($ownDumper);
            },
            100
        );

        $target = $this->createResolver($dispatcher, $this->createRepository($this->createEntity('mysql')))
            ->resolveForBackup($settings);

        static::assertSame($ownDumper, $target->getDumper());
        static::assertNotSame($mysqlDumper, $target->getDumper());
        static::assertSame('db.example.org', $target->getConnection()->getHost());
    }

    private function createMysqlSubscriber(?MysqlDatabaseDumper &$mysqlDumper = null): MysqlDatabaseConnectionSubscriber
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getDatabaseUrl')->willReturn('mysql://root:root@database:3306/shopware');

        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->method('resolve')->willReturn('s3cret');

        $mysqlDumper = $this->createMock(MysqlDatabaseDumper::class);

        return new MysqlDatabaseConnectionSubscriber($settings, $passwordResolver, $mysqlDumper);
    }

    private function createResolver(
        EventDispatcherInterface $dispatcher,
        EntityRepository $repository,
        string $databaseUrl = 'mysql://root:root@database:3306/shopware'
    ): DatabaseConnectionResolver {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getDatabaseUrl')->willReturn($databaseUrl);

        return new DatabaseConnectionResolver($dispatcher, $repository, $settings);
    }

    private function createRepository(?DatabaseConnectionEntity $entity): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($entity): EntitySearchResult {
                $collection = new DatabaseConnectionCollection($entity !== null ? [$entity] : []);

                return new EntitySearchResult($entity?->getApiAlias() ?? 'muwa_database_connection', $collection->count(), $collection, null, $criteria, $context);
            }
        );

        return $repository;
    }

    private function createEntity(string $type): DatabaseConnectionEntity
    {
        $entity = new DatabaseConnectionEntity();
        $entity->setId(self::CONNECTION_ID);
        $entity->setName('Shop replica');
        $entity->setType($type);
        $entity->setHost('db.example.org');
        $entity->setPort(null);
        $entity->setDatabaseName('shop');
        $entity->setUsername('backup');
        $entity->setPassword('v1:cipher');
        $entity->setPasswordSource(PasswordSource::ENCRYPTED->value);

        return $entity;
    }
}
