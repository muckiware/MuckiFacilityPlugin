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
use Symfony\Component\Process\Process;

use MuckiFacilityPlugin\Backup\Database\MysqlDatabaseDumper;
use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;

class MysqlDatabaseDumperTest extends TestCase
{
    private const DATABASE_URL = 'mysql://root:root@database:3306/shopware';
    private const PASSWORD = 's3cret-Pa55';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/muwa-dumper-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
    }

    public function testDefaultConnectionDumpUsesDatabaseUrlAndSingleTransaction(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->expects(static::once())->method('setDatabaseUrl')->with(self::DATABASE_URL);
        $spatieDumper->expects(static::once())->method('useSingleTransaction');
        $spatieDumper->expects(static::once())->method('addExtraOption')->with('--no-tablespaces');
        $spatieDumper->expects(static::never())->method('setHost');
        $spatieDumper->expects(static::never())->method('setPassword');
        $spatieDumper->expects(static::never())->method('includeTables');

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createDefaultConnection(), $this->workDir . '/dump.sql');

        static::assertFileExists($this->workDir . '/dump.sql');
    }

    public function testDefaultConnectionDumpIncludesGivenTables(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->expects(static::once())->method('setDatabaseUrl')->with(self::DATABASE_URL);
        $spatieDumper->expects(static::once())->method('includeTables')->with(['product']);

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createDefaultConnection(), $this->workDir . '/product.sql', ['product']);

        static::assertFileExists($this->workDir . '/product.sql');
    }

    public function testOwnConnectionDumpSetsHostPortNameUserPassword(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->expects(static::never())->method('setDatabaseUrl');
        $spatieDumper->expects(static::once())->method('setHost')->with('db.example.org');
        $spatieDumper->expects(static::once())->method('setPort')->with(3307);
        $spatieDumper->expects(static::once())->method('setDbName')->with('shop');
        $spatieDumper->expects(static::once())->method('setUserName')->with('backup');
        $spatieDumper->expects(static::once())->method('setPassword')->with(self::PASSWORD);
        $spatieDumper->expects(static::once())->method('useSingleTransaction');
        $spatieDumper->expects(static::once())->method('addExtraOption')->with('--no-tablespaces');

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createOwnConnection(), $this->workDir . '/shop.sql');

        static::assertFileExists($this->workDir . '/shop.sql');
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

    /**
     * S-01: spatie inserts the database name unescaped into a shell command line —
     * a name with shell metacharacters must never reach the dumper.
     */
    public function testOwnConnectionDumpRejectsShellMetacharactersInDatabaseName(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->expects(static::never())->method('setDbName');

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $connection = new ResolvedDatabaseConnection(
            'mysql',
            'db.example.org',
            3307,
            'shop; echo pwn #',
            'backup',
            self::PASSWORD
        );

        try {
            $dumper->dump($connection, '/tmp/shop.sql');
            static::fail('dump should have thrown');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringContainsString('Invalid database identifier', $e->getMessage());
            static::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    /**
     * S-01: table names land unescaped in the --tables shell argument.
     */
    public function testDumpRejectsShellMetacharactersInTableNames(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->expects(static::never())->method('includeTables');

        $dumper = $this->createDumperWithSpatie($spatieDumper);

        try {
            $dumper->dump($this->createDefaultConnection(), '/tmp/dump.sql', ['product; touch /tmp/pwn']);
            static::fail('dump should have thrown');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringContainsString('Table name', $e->getMessage());
        }
    }

    public function testDumpFailureIsWrappedWithLegacyPrefixAndMaskedPassword(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->method('checkIfDumpWasSuccessFul')->willThrowException(new DumpFailed('mysqldump said ' . self::PASSWORD));

        $dumper = $this->createDumperWithSpatie($spatieDumper);

        try {
            $dumper->dump($this->createOwnConnection(), $this->workDir . '/shop.sql');
            static::fail('dump should have thrown');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringStartsWith('Dump failed:', $e->getMessage());
            static::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function testCannotStartDumpKeepsLegacyPrefixForDefaultConnection(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->method('guardAgainstIncompleteCredentials')->willThrowException(new CannotStartDump('missing credentials'));

        $dumper = $this->createDumperWithSpatie($spatieDumper);

        $this->expectException(DatabaseBackupFailedException::class);
        $this->expectExceptionMessage('Cannot start dump:missing credentials');

        $dumper->dump($this->createDefaultConnection(), $this->workDir . '/dump.sql');
    }

    public function testConfiguredDumpOptionsReplaceTheDefaults(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $arguments = [];
        $spatieDumper->method('addExtraOption')->willReturnCallback(
            function (string $argument) use (&$arguments, $spatieDumper): MySql {
                $arguments[] = $argument;
                return $spatieDumper;
            }
        );

        $connection = new ResolvedDatabaseConnection(
            'mysql', 'db.example.org', 3307, 'shop', 'backup', self::PASSWORD,
            [MysqlDatabaseDumper::EXTRA_DUMP_OPTIONS => ['events', 'routines', 'setGtidPurgedOff']]
        );
        $this->createDumperWithSpatie($spatieDumper)->dump($connection, $this->workDir . '/shop.sql');

        static::assertSame(['--routines', '--events', '--set-gtid-purged=OFF'], $arguments);
    }

    public function testEmptyDumpOptionsAddNoExtraOption(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->expects(static::never())->method('addExtraOption');

        $connection = new ResolvedDatabaseConnection(
            'mysql', 'db.example.org', 3307, 'shop', 'backup', self::PASSWORD,
            [MysqlDatabaseDumper::EXTRA_DUMP_OPTIONS => []]
        );
        $this->createDumperWithSpatie($spatieDumper)->dump($connection, $this->workDir . '/shop.sql');
    }

    /**
     * Rows or third-party subscribers may carry arbitrary values; only allowlisted arguments may
     * reach the shell command line.
     */
    public function testUnknownDumpOptionsNeverReachMysqldump(): void
    {
        $dumpDir = $this->workDir . '/dump';
        mkdir($dumpDir);

        $connection = new ResolvedDatabaseConnection(
            'mysql', 'db.example.org', 3307, 'shop', 'backup', self::PASSWORD,
            [MysqlDatabaseDumper::EXTRA_DUMP_OPTIONS => ['hexBlob', '--routines; touch pwned', 'routines']]
        );
        $dumper = $this->createDumperWithSpatie(MySql::create()->setDumpBinaryPath($this->createArgumentEchoMysqldump()));
        $dumper->dump($connection, $dumpDir . '/shop.sql');

        $arguments = (string) file_get_contents($dumpDir . '/shop.sql');
        static::assertStringContainsString(' --routines --hex-blob ', ' ' . trim($arguments) . ' ');
        static::assertStringNotContainsString('--no-tablespaces', $arguments);
        static::assertStringNotContainsString('touch', $arguments);
        static::assertSame(['shop.sql'], array_values(array_diff(scandir($dumpDir) ?: [], ['.', '..'])));
    }

    public function testFailedDumpRemovesPartialFileAndCreatesNoDumpFile(): void
    {
        $spatieDumper = $this->createSpatieMock();
        $spatieDumper->method('checkIfDumpWasSuccessFul')->willThrowException(new DumpFailed('connection lost'));

        $dumper = $this->createDumperWithSpatie($spatieDumper);

        try {
            $dumper->dump($this->createOwnConnection(), $this->workDir . '/shop.sql');
            static::fail('dump should have thrown');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringStartsWith('Dump failed:', $e->getMessage());
        }

        static::assertSame([], $this->listWorkDir());
    }

    public function testShellReceivesOnlyGeneratedPartialFileName(): void
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->expects(static::once())
            ->method('getProcess')
            ->with(static::matchesRegularExpression('/^\.muwa-dump-[0-9a-f]{16}\.partial$/'))
            ->willReturnCallback(fn (string $file): Process => $this->createWritingProcess($file));

        $dumper = $this->createDumperWithSpatie($spatieDumper);
        $dumper->dump($this->createOwnConnection(), $this->workDir . '/shop.sql');

        static::assertSame(['shop.sql'], $this->listWorkDir());
    }

    /**
     * Regression for the shell injection through the dump path: spatie only escapes \ and " in the
     * output file, $(...) in the configured directory used to be executed by the shell.
     */
    public function testDumpPathWithShellSyntaxIsNeverExecuted(): void
    {
        // the shell runs in the dump directory, an executed touch would create the marker there
        $dumpDir = $this->workDir . '/dump $(touch pwned-a) `touch pwned-b`';
        mkdir($dumpDir);

        $dumper = $this->createDumperWithSpatie(MySql::create()->setDumpBinaryPath($this->createFakeMysqldump(0)));
        $dumper->dump($this->createOwnConnection(), $dumpDir . '/shop.sql');

        static::assertSame(['shop.sql'], array_values(array_diff(scandir($dumpDir) ?: [], ['.', '..'])));
        static::assertSame("-- fake dump\n", file_get_contents($dumpDir . '/shop.sql'));
    }

    public function testAbortedMysqldumpLeavesNoHalfDump(): void
    {
        $dumpDir = $this->workDir . '/dump';
        mkdir($dumpDir);

        $dumper = $this->createDumperWithSpatie(MySql::create()->setDumpBinaryPath($this->createFakeMysqldump(2)));

        try {
            $dumper->dump($this->createOwnConnection(), $dumpDir . '/shop.sql');
            static::fail('dump should have thrown');
        } catch (DatabaseBackupFailedException $e) {
            static::assertStringStartsWith('Dump failed:', $e->getMessage());
            static::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }

        static::assertSame([], array_values(array_diff(scandir($dumpDir) ?: [], ['.', '..'])));
    }

    /**
     * Mock whose process really writes the partial file, so the rename to the target name can run.
     */
    private function createSpatieMock(): MySql&MockObject
    {
        $spatieDumper = $this->createMock(MySql::class);
        $spatieDumper->method('getProcess')->willReturnCallback(
            fn (string $file): Process => $this->createWritingProcess($file)
        );

        return $spatieDumper;
    }

    private function createWritingProcess(string $file): Process
    {
        return Process::fromShellCommandline('printf "%s" "-- dump" > ' . escapeshellarg($file));
    }

    /**
     * Fake mysqldump that writes a partial dump and exits with the given code.
     *
     * @return string directory containing the fake binary
     */
    private function createFakeMysqldump(int $exitCode): string
    {
        $binDir = $this->workDir . '/bin';
        if (!is_dir($binDir)) {
            mkdir($binDir);
        }
        file_put_contents($binDir . '/mysqldump', "#!/bin/sh\necho '-- fake dump'\nexit " . $exitCode . "\n");
        chmod($binDir . '/mysqldump', 0755);

        return $binDir;
    }

    /**
     * Fake mysqldump that writes its own arguments as dump content.
     */
    private function createArgumentEchoMysqldump(): string
    {
        $binDir = $this->workDir . '/bin';
        if (!is_dir($binDir)) {
            mkdir($binDir);
        }
        file_put_contents($binDir . '/mysqldump', "#!/bin/sh\necho \"$@\"\n");
        chmod($binDir . '/mysqldump', 0755);

        return $binDir;
    }

    /**
     * @return array<int, string>
     */
    private function listWorkDir(): array
    {
        return array_values(array_diff(scandir($this->workDir) ?: [], ['.', '..']));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
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
