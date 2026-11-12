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
namespace MuckiFacilityPlugin\tests\Backup\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Exceptions\CannotStartDump;
use Spatie\DbDumper\Exceptions\DumpFailed;

use MuckiFacilityPlugin\Backup\Database\MysqlDatabaseDumper;
use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;

class MysqlDatabaseDumperTest extends TestCase
{
    private const DATABASE_URL = 'mysql://root:root@database:3306/shopware';
    private const PASSWORD = 's3cret-Pa55';

    public function testDefaultConnectionDumpUsesDatabaseUrlAndSingleTransaction(): void
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->expects(static::once())->method('setDatabaseUrl')->with(self::DATABASE_URL);
        $spatieDumper->expects(static::once())->method('useSingleTransaction');
        $spatieDumper->expects(static::never())->method('setHost');
        $spatieDumper->expects(static::never())->method('setPassword');
        $spatieDumper->expects(static::never())->method('includeTables');
        $spatieDumper->expects(static::once())->method('dumpToFile')->with('/tmp/dump.sql');

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createDefaultConnection(), '/tmp/dump.sql');
    }

    public function testDefaultConnectionDumpIncludesGivenTables(): void
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->expects(static::once())->method('setDatabaseUrl')->with(self::DATABASE_URL);
        $spatieDumper->expects(static::once())->method('includeTables')->with(['product']);
        $spatieDumper->expects(static::once())->method('dumpToFile')->with('/tmp/product.sql');

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createDefaultConnection(), '/tmp/product.sql', ['product']);
    }

    public function testOwnConnectionDumpSetsHostPortNameUserPassword(): void
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->expects(static::never())->method('setDatabaseUrl');
        $spatieDumper->expects(static::once())->method('setHost')->with('db.example.org');
        $spatieDumper->expects(static::once())->method('setPort')->with(3307);
        $spatieDumper->expects(static::once())->method('setDbName')->with('shop');
        $spatieDumper->expects(static::once())->method('setUserName')->with('backup');
        $spatieDumper->expects(static::once())->method('setPassword')->with(self::PASSWORD);
        $spatieDumper->expects(static::once())->method('useSingleTransaction');
        $spatieDumper->expects(static::once())->method('dumpToFile')->with('/tmp/shop.sql');

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createOwnConnection(), '/tmp/shop.sql');
    }

    public function testDefaultListTablesUsesCoreDatabase(): void
    {
        $coreDatabase = $this->createMock(CoreDatabase::class);
        $coreDatabase->expects(static::once())->method('getListOfAllTables')->willReturn(['product', 'order']);

        $dumper = $this->getMockBuilder(MysqlDatabaseDumper::class)
            ->setConstructorArgs([$coreDatabase])
            ->onlyMethods(['createConnection'])
            ->getMock();
        $dumper->expects(static::never())->method('createConnection');

        static::assertSame(['product', 'order'], $dumper->listTables($this->createDefaultConnection()));
    }

    public function testOwnListTablesUsesShortLivedConnection(): void
    {
        $coreDatabase = $this->createMock(CoreDatabase::class);
        $coreDatabase->expects(static::never())->method('getListOfAllTables');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->with('SHOW TABLES')->willReturn(['a', 'b']);
        $connection->expects(static::once())->method('close');

        $dumper = $this->createDumperWithConnection($connection, $coreDatabase);

        static::assertSame(['a', 'b'], $dumper->listTables($this->createOwnConnection()));
    }

    public function testConnectionErrorMessageNeverContainsPassword(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user backup (password ' . self::PASSWORD . ')')
        );
        $connection->method('fetchFirstColumn')->willThrowException(
            new \RuntimeException('Connection refused, password ' . self::PASSWORD)
        );
        $connection->expects(static::atLeastOnce())->method('close');

        $dumper = $this->createDumperWithConnection($connection, $this->createMock(CoreDatabase::class));

        foreach (['testConnection', 'listTables'] as $method) {
            try {
                $dumper->$method($this->createOwnConnection());
                static::fail($method . ' should have thrown');
            } catch (DatabaseBackupFailedException $e) {
                static::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
                static::assertStringContainsString('***', $e->getMessage());
                static::assertNull($e->getPrevious(), 'The original exception may contain the password');
            }
        }
    }

    public function testDumpFailureIsWrappedWithLegacyPrefixAndMaskedPassword(): void
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->method('dumpToFile')->willThrowException(new DumpFailed('mysqldump said ' . self::PASSWORD));

        $dumper = $this->createDumperWithSpatie($spatieDumper);

        try {
            $dumper->dump($this->createOwnConnection(), '/tmp/shop.sql');
            static::fail('dump should have thrown');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringStartsWith('Dump failed:', $e->getMessage());
            static::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function testCannotStartDumpKeepsLegacyPrefixForDefaultConnection(): void
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->method('dumpToFile')->willThrowException(new CannotStartDump('missing credentials'));

        $dumper = $this->createDumperWithSpatie($spatieDumper);

        $this->expectException(DatabaseBackupFailedException::class);
        $this->expectExceptionMessage('Cannot start dump:missing credentials');

        $dumper->dump($this->createDefaultConnection(), '/tmp/dump.sql');
    }

    private function createDumperWithSpatie(MySql $spatieDumper): MysqlDatabaseDumper&MockObject
    {
        $dumper = $this->getMockBuilder(MysqlDatabaseDumper::class)
            ->setConstructorArgs([$this->createMock(CoreDatabase::class)])
            ->onlyMethods(['createDumper'])
            ->getMock();
        $dumper->method('createDumper')->willReturn($spatieDumper);

        return $dumper;
    }

    private function createDumperWithConnection(Connection $connection, CoreDatabase $coreDatabase): MysqlDatabaseDumper&MockObject
    {
        $dumper = $this->getMockBuilder(MysqlDatabaseDumper::class)
            ->setConstructorArgs([$coreDatabase])
            ->onlyMethods(['createConnection'])
            ->getMock();
        $dumper->method('createConnection')->willReturn($connection);

        return $dumper;
    }

    private function createDefaultConnection(): ResolvedDatabaseConnection
    {
        return new ResolvedDatabaseConnection('mysql', 'database', 3306, 'shopware', 'root', 'root', [], self::DATABASE_URL);
    }

    private function createOwnConnection(): ResolvedDatabaseConnection
    {
        return new ResolvedDatabaseConnection('mysql', 'db.example.org', 3307, 'shop', 'backup', self::PASSWORD);
    }
}
