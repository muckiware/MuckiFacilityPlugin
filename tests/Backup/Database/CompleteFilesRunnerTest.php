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
use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
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
            $this->createMock(CoreDatabase::class)
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
            $this->createMock(CoreDatabase::class)
        );

        static::assertSame(
            '/var/www/html/var/db/backup/'.self::DATESTAMP.'/'.self::DATE_TIMESTAMP.'_product.backup.sql',
            $runner->createBackupFileName('product', true),
            'Without a configured dump path the default backup path should be used'
        );
    }
}
