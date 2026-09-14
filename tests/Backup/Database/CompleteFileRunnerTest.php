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
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\SettingsInterface;

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
            $createBackup
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
            new BackupRepositorySettings()
        );

        static::assertSame(
            '/var/www/html/var/db/backup/'.self::DATE_TIMESTAMP.'_shopware.backup.sql',
            $runner->createBackupFileName('shopware'),
            'Without a configured dump path the default backup path should be used'
        );
    }

    public function testCreateBackupFileNameWithCompression(): void
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
            $createBackup
        );

        static::assertSame(
            self::DUMP_PATH.'/'.self::DATE_TIMESTAMP.'_shopware.backup.sql.gz',
            $runner->createBackupFileName('shopware'),
            'A compressed dump should get the gz suffix'
        );
    }
}
