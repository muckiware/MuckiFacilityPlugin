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

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Backup\Database\CompleteFilesRunner;
use MuckiFacilityPlugin\Backup\Database\DumpArchiver;
use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Entity\DatabaseBackupTarget;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\SettingsInterface;

class CompleteFilesRunnerTest extends TestCase
{
    private const DUMP_PATH = '/mnt/backup/dump';
    private const DATESTAMP = '2026-08-21';
    private const DATE_TIMESTAMP = '2026-08-21_14-08-02';

    public function testCreateBackupFileNameUsesConfiguredDbDumpPathWithSubFolder(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setDbDumpPath(self::DUMP_PATH);

        $settings = $this->createMock(SettingsInterface::class);
        $settings->expects(static::once())
            ->method('getBackupPath')
            ->with(true, self::DUMP_PATH)
            ->willReturn(self::DUMP_PATH.'/'.self::DATESTAMP);
        $settings->method('getDateTimestamp')->willReturn(self::DATE_TIMESTAMP);
        $settings->method('isCompressDbBackupEnabled')->willReturn(false);

        $runner = new CompleteFilesRunner(
            $this->createMock(LoggerInterface::class),
            $settings,
            $createBackup,
            $this->createTarget(),
            $this->createMock(DumpArchiver::class)
        );

        static::assertSame(
            self::DUMP_PATH.'/'.self::DATESTAMP.'/'.self::DATE_TIMESTAMP.'_product.backup.sql',
            $runner->createBackupFileName('product', true),
            'The configured database dump path plus datestamp sub folder should be used per table dump'
        );
    }

    public function testCreateBackupFileNameWithoutConfiguredDbDumpPath(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->expects(static::once())
            ->method('getBackupPath')
            ->with(true, null)
            ->willReturn('/var/www/html/var/db/backup/'.self::DATESTAMP);
        $settings->method('getDateTimestamp')->willReturn(self::DATE_TIMESTAMP);
        $settings->method('isCompressDbBackupEnabled')->willReturn(false);

        $runner = new CompleteFilesRunner(
            $this->createMock(LoggerInterface::class),
            $settings,
            new BackupRepositorySettings(),
            $this->createTarget(),
            $this->createMock(DumpArchiver::class)
        );

        static::assertSame(
            '/var/www/html/var/db/backup/'.self::DATESTAMP.'/'.self::DATE_TIMESTAMP.'_product.backup.sql',
            $runner->createBackupFileName('product', true),
            'Without a configured dump path the default backup path should be used'
        );
    }

    public function testUsesTableListOfTargetDumper(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->expects(static::once())->method('listTables')->willReturn(['a', 'b']);
        $dumped = [];
        $dumper->method('dump')->willReturnCallback(
            static function (ResolvedDatabaseConnection $c, string $file, array $tables) use (&$dumped): void {
                $dumped[] = [$file, $tables];
            }
        );
        $archiver = $this->createMock(DumpArchiver::class);
        $archiver->expects(static::exactly(2))->method('archive');

        $runner = new CompleteFilesRunner(
            $this->createMock(LoggerInterface::class),
            $this->createSettings(true),
            new BackupRepositorySettings(),
            $this->createTarget($dumper, true),
            $archiver
        );
        $runner->createBackupData();

        static::assertSame([
            ['/dump/'.self::DATE_TIMESTAMP.'_a.backup.sql', ['a']],
            ['/dump/'.self::DATE_TIMESTAMP.'_b.backup.sql', ['b']],
        ], $dumped);
    }

    public function testOwnConnectionAbortsAtFirstFailingTable(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->method('listTables')->willReturn(['a', 'b', 'c']);
        $dumped = [];
        $dumper->method('dump')->willReturnCallback(
            static function (ResolvedDatabaseConnection $c, string $file, array $tables) use (&$dumped): void {
                $dumped[] = $tables[0];
                if ($tables[0] === 'b') {
                    throw new DatabaseBackupFailedException('Dump failed:boom');
                }
            }
        );

        $runner = new CompleteFilesRunner(
            $this->createMock(LoggerInterface::class),
            $this->createSettings(false),
            new BackupRepositorySettings(),
            $this->createTarget($dumper, false, 'Reporting'),
            $this->createMock(DumpArchiver::class)
        );

        try {
            $runner->createBackupData();
            static::fail('Expected DatabaseBackupFailedException');
        } catch (DatabaseBackupFailedException $e) {
            static::assertSame('Database backup for connection "Reporting" failed: Dump failed:boom', $e->getMessage());
        }
        static::assertSame(['a', 'b'], $dumped, 'Table c must never be dumped');
    }

    public function testDefaultConnectionContinuesAfterFailingTable(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->method('listTables')->willReturn(['a', 'b', 'c']);
        $dumped = [];
        $dumper->method('dump')->willReturnCallback(
            static function (ResolvedDatabaseConnection $c, string $file, array $tables) use (&$dumped): void {
                $dumped[] = $tables[0];
                if ($tables[0] === 'b') {
                    throw new DatabaseBackupFailedException('Dump failed:boom');
                }
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error')->with('Dump failed:boom', PluginDefaults::DEFAULT_LOGGER_CONFIG);

        $runner = new CompleteFilesRunner(
            $logger,
            $this->createSettings(false),
            new BackupRepositorySettings(),
            $this->createTarget($dumper, true),
            $this->createMock(DumpArchiver::class)
        );
        $runner->createBackupData();

        static::assertSame(['a', 'b', 'c'], $dumped);
    }

    private function createSettings(bool $compress): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getBackupPath')->willReturn('/dump');
        $settings->method('getDateTimestamp')->willReturn(self::DATE_TIMESTAMP);
        $settings->method('isCompressDbBackupEnabled')->willReturn($compress);

        return $settings;
    }

    private function createTarget(
        ?DatabaseDumperInterface $dumper = null,
        bool $isDefault = true,
        string $name = 'default'
    ): DatabaseBackupTarget {
        return new DatabaseBackupTarget(
            new ResolvedDatabaseConnection('mysql', 'localhost', 3306, 'shopware', 'root', 'secret'),
            $dumper ?? $this->createMock(DatabaseDumperInterface::class),
            $isDefault,
            $name
        );
    }
}
