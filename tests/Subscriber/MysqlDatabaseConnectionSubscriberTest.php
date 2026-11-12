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
namespace MuckiFacilityPlugin\tests\Subscriber;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

use MuckiFacilityPlugin\Backup\Database\MysqlDatabaseDumper;
use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Events\ResolveDatabaseConnectionEvent;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Exception\UnresolvableDatabasePasswordException;
use MuckiFacilityPlugin\Services\Content\DatabasePasswordResolver;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Subscriber\MysqlDatabaseConnectionSubscriber;

class MysqlDatabaseConnectionSubscriberTest extends TestCase
{
    private const DATABASE_URL = 'mysql://root:r%40ot@database:3306/shopware';

    public function testSubscribesToResolveEventWithPriorityZero(): void
    {
        static::assertSame(
            [ResolveDatabaseConnectionEvent::NAME => ['onResolveDatabaseConnection', 0]],
            MysqlDatabaseConnectionSubscriber::getSubscribedEvents()
        );
    }

    public function testIgnoresOtherTypes(): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->expects(static::never())->method('resolve');

        $event = new ResolveDatabaseConnectionEvent('postgres', $this->createEntity('postgres', null), null, Context::createDefaultContext());
        $this->createSubscriber($passwordResolver)->onResolveDatabaseConnection($event);

        static::assertFalse($event->isResolved());
        static::assertNull($event->getResolvedConnection());
        static::assertNull($event->getDumper());
    }

    public function testDefaultConnectionUsesDatabaseUrl(): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->expects(static::never())->method('resolve');

        $dumper = $this->createMock(MysqlDatabaseDumper::class);
        $event = new ResolveDatabaseConnectionEvent('mysql', null, null, Context::createDefaultContext());
        $this->createSubscriber($passwordResolver, $dumper)->onResolveDatabaseConnection($event);

        static::assertTrue($event->isResolved());
        static::assertTrue($event->isDefaultConnection());
        static::assertSame($dumper, $event->getDumper());

        $connection = $event->getResolvedConnection();
        static::assertInstanceOf(ResolvedDatabaseConnection::class, $connection);
        static::assertSame(self::DATABASE_URL, $connection->getDatabaseUrl());
        static::assertSame('mysql', $connection->getType());
        static::assertSame('database', $connection->getHost());
        static::assertSame(3306, $connection->getPort());
        static::assertSame('shopware', $connection->getDatabaseName());
        static::assertSame('root', $connection->getUsername());
        static::assertSame('r@ot', $connection->getPassword());
    }

    public function testOwnConnectionResolvesPasswordAndDefaultPort(): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->expects(static::once())
            ->method('resolve')
            ->with('MUWA_DB_SHOP', PasswordSource::ENV)
            ->willReturn('s3cret');

        $event = new ResolveDatabaseConnectionEvent('mysql', $this->createEntity('mysql', null), null, Context::createDefaultContext());
        $this->createSubscriber($passwordResolver)->onResolveDatabaseConnection($event);

        $connection = $event->getResolvedConnection();
        static::assertInstanceOf(ResolvedDatabaseConnection::class, $connection);
        static::assertNull($connection->getDatabaseUrl());
        static::assertSame('db.example.org', $connection->getHost());
        static::assertSame(3306, $connection->getPort());
        static::assertSame('shop', $connection->getDatabaseName());
        static::assertSame('backup', $connection->getUsername());
        static::assertSame('s3cret', $connection->getPassword());
        static::assertInstanceOf(DatabaseDumperInterface::class, $event->getDumper());
        static::assertTrue($event->isResolved());
    }

    public function testOwnConnectionKeepsConfiguredPort(): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->method('resolve')->willReturn('s3cret');

        $event = new ResolveDatabaseConnectionEvent('mysql', $this->createEntity('mysql', 3307), null, Context::createDefaultContext());
        $this->createSubscriber($passwordResolver)->onResolveDatabaseConnection($event);

        static::assertSame(3307, $event->getResolvedConnection()?->getPort());
    }

    public function testUnresolvablePasswordThrowsWithConnectionName(): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->method('resolve')->willThrowException(
            new UnresolvableDatabasePasswordException('Environment variable MUWA_DB_SHOP is not set or empty')
        );

        $event = new ResolveDatabaseConnectionEvent('mysql', $this->createEntity('mysql', null), null, Context::createDefaultContext());

        $this->expectException(DatabaseBackupFailedException::class);
        $this->expectExceptionMessageMatches('/"Shop replica".*MUWA_DB_SHOP is not set or empty/');

        $this->createSubscriber($passwordResolver)->onResolveDatabaseConnection($event);
    }

    /**
     * Defence in depth: spatie writes host and user unescaped into the mysqldump credentials file.
     *
     * @dataProvider controlCharacterProvider
     */
    public function testOwnConnectionWithControlCharacterInTargetFieldIsRejected(string $field, string $value): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->expects(static::never())->method('resolve');

        $entity = $this->createEntity('mysql', null);
        match ($field) {
            'host' => $entity->setHost($value),
            'username' => $entity->setUsername($value),
            'databaseName' => $entity->setDatabaseName($value),
        };
        $event = new ResolveDatabaseConnectionEvent('mysql', $entity, null, Context::createDefaultContext());

        try {
            $this->createSubscriber($passwordResolver)->onResolveDatabaseConnection($event);
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringContainsString('"Shop replica"', $e->getMessage());
            static::assertStringNotContainsString('MUWA_DB_SHOP', $e->getMessage());
        }
        static::assertNull($event->getResolvedConnection());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function controlCharacterProvider(): array
    {
        return [
            'line feed in host' => ['host', "db.example.org\nuser=root"],
            'carriage return in username' => ['username', "backup\rhost=evil"],
            'nul in database name' => ['databaseName', "shop\0"],
        ];
    }

    public function testKeepsConnectionAndDumperSetByEarlierListener(): void
    {
        $passwordResolver = $this->createMock(DatabasePasswordResolver::class);
        $passwordResolver->expects(static::never())->method('resolve');

        $ownConnection = new ResolvedDatabaseConnection('mysql', 'vault-host', 3306, 'shop', 'backup', 'from-vault');
        $ownDumper = $this->createMock(DatabaseDumperInterface::class);

        $event = new ResolveDatabaseConnectionEvent('mysql', $this->createEntity('mysql', null), null, Context::createDefaultContext());
        $event->setResolvedConnection($ownConnection);
        $event->setDumper($ownDumper);

        $this->createSubscriber($passwordResolver)->onResolveDatabaseConnection($event);

        static::assertSame($ownConnection, $event->getResolvedConnection());
        static::assertSame($ownDumper, $event->getDumper());
    }

    private function createSubscriber(
        DatabasePasswordResolver $passwordResolver,
        ?MysqlDatabaseDumper $dumper = null
    ): MysqlDatabaseConnectionSubscriber {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getDatabaseUrl')->willReturn(self::DATABASE_URL);

        return new MysqlDatabaseConnectionSubscriber(
            $settings,
            $passwordResolver,
            $dumper ?? $this->createMock(MysqlDatabaseDumper::class)
        );
    }

    private function createEntity(string $type, ?int $port): DatabaseConnectionEntity
    {
        $entity = new DatabaseConnectionEntity();
        $entity->setId('0190e6f1b0a07e0b9a3c4d5e6f708192');
        $entity->setName('Shop replica');
        $entity->setType($type);
        $entity->setHost('db.example.org');
        $entity->setPort($port);
        $entity->setDatabaseName('shop');
        $entity->setUsername('backup');
        $entity->setPassword('MUWA_DB_SHOP');
        $entity->setPasswordSource(PasswordSource::ENV->value);

        return $entity;
    }
}
