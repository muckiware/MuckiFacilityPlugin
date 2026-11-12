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

use MuckiFacilityPlugin\Backup\Database\CompleteFileRunner;
use MuckiFacilityPlugin\Backup\Database\DumpArchiver;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Entity\DatabaseBackupTarget;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

class CompleteFileRunnerTest extends TestCase
{
    private const DUMP_PATH = '/mnt/backup/dump';
    private const DATE_TIMESTAMP = '2026-08-21_14-08-02';

    public function testCreateBackupFileNameUsesConfiguredDbDumpPath(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setDbDumpPath(self::DUMP_PATH);

        $settings = $this->createMock(SettingsInterface::class);
        $settings->expects(static::once())
            ->method('getBackupPath')
            ->with(false, self::DUMP_PATH)
            ->willReturn(self::DUMP_PATH);
        $settings->method('getDateTimestamp')->willReturn(self::DATE_TIMESTAMP);
        $settings->method('isCompressDbBackupEnabled')->willReturn(false);

        $runner = new CompleteFileRunner(
            $this->createMock(LoggerInterface::class),
            $settings,
            $createBackup,
            $this->createTarget(),
            $this->createMock(DumpArchiver::class)
        );

        static::assertSame(
            self::DUMP_PATH.'/'.self::DATE_TIMESTAMP.'_shopware.backup.sql',
            $runner->createBackupFileName('shopware'),
            'The configured database dump path should be used for the dump file name'
        );
    }

    public function testCreateBackupFileNameWithoutConfiguredDbDumpPath(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->expects(static::once())
            ->method('getBackupPath')
            ->with(false, null)
            ->willReturn('/var/www/html/var/db/backup');
        $settings->method('getDateTimestamp')->willReturn(self::DATE_TIMESTAMP);
        $settings->method('isCompressDbBackupEnabled')->willReturn(false);

        $runner = new CompleteFileRunner(
            $this->createMock(LoggerInterface::class),
            $settings,
            new BackupRepositorySettings(),
            $this->createTarget(),
            $this->createMock(DumpArchiver::class)
        );

        static::assertSame(
            '/var/www/html/var/db/backup/'.self::DATE_TIMESTAMP.'_shopware.backup.sql',
            $runner->createBackupFileName('shopware'),
            'Without a configured dump path the default backup path should be used'
        );
    }

    public function testCreateBackupFileNameIsPlainSqlEvenWithCompression(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setDbDumpPath(self::DUMP_PATH);

        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getBackupPath')->willReturn(self::DUMP_PATH);
        $settings->method('getDateTimestamp')->willReturn(self::DATE_TIMESTAMP);
        $settings->method('isCompressDbBackupEnabled')->willReturn(true);

        $runner = new CompleteFileRunner(
            $this->createMock(LoggerInterface::class),
            $settings,
            $createBackup,
            $this->createTarget(),
            $this->createMock(DumpArchiver::class)
        );

        static::assertSame(
            self::DUMP_PATH.'/'.self::DATE_TIMESTAMP.'_shopware.backup.sql',
            $runner->createBackupFileName('shopware'),
            'mysqldump always writes plain sql, the tar.gz archive is created afterwards by the DumpArchiver'
        );
    }

    public function testDefaultConnectionKeepsLegacyErrorBehaviour(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->method('dump')->willThrowException(new DatabaseBackupFailedException('Dump failed:boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error')->with('Dump failed:boom', PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $archiver = $this->createMock(DumpArchiver::class);
        $archiver->expects(static::never())->method('archive');

        $runner = new CompleteFileRunner(
            $logger,
            $this->createSettings(true),
            new BackupRepositorySettings(),
            $this->createTarget($dumper, true),
            $archiver
        );

        $runner->createBackupData();
    }

    public function testOwnConnectionDumpFailureThrows(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->method('dump')->willThrowException(new DatabaseBackupFailedException('Dump failed:boom'));

        $runner = new CompleteFileRunner(
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
            static::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testDumpFileNameContainsDatabaseNameAndIsArchived(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->expects(static::once())
            ->method('dump')
            ->with(static::isInstanceOf(ResolvedDatabaseConnection::class), '/dump/'.self::DATE_TIMESTAMP.'_shopware.backup.sql');
        $archiver = $this->createMock(DumpArchiver::class);
        $archiver->expects(static::once())->method('archive')->with('/dump/'.self::DATE_TIMESTAMP.'_shopware.backup.sql');

        $runner = new CompleteFileRunner(
            $this->createMock(LoggerInterface::class),
            $this->createSettings(true),
            new BackupRepositorySettings(),
            $this->createTarget($dumper, true),
            $archiver
        );

        $runner->createBackupData();
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
