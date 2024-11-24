<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Backup\Database;

use Psr\Log\LoggerInterface;
use Spatie\DbDumper\Databases\MySql as MySqlDumper;
use Spatie\DbDumper\Exceptions\CannotStartDump;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Spatie\DbDumper\Compressors\GzipCompressor;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;

class CompleteFileRunner implements BackupInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $pluginSettings
    ) {}
    public function createBackupData(): void
    {
        $mysqlDumper = MySqlDumper::create();
        $mysqlDumper->setDatabaseUrl($this->pluginSettings->getDatabaseUrl());
        if($this->pluginSettings->isCompressDbBackupEnabled()) {
            $mysqlDumper->useCompressor(new GzipCompressor());
        }
        $mysqlDumper->useSingleTransaction();

        try {
            $mysqlDumper->dumpToFile($this->createBackupFileName($mysqlDumper->getDbName()));
        } catch (CannotStartDump $e) {
            $this->logger->error('Cannot start dump:'.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        } catch (DumpFailed $e) {
            $this->logger->error('Dump failed:'.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }

    public function getBackupData(): mixed
    {
        return array();
    }

    public function saveBackupData(mixed $data): void
    {
        // TODO: Implement saveBackupData() method.
    }

    public function removeBackupData(): void
    {
        // TODO: Implement removeBackupData() method.
    }

    protected function createBackupFileName(string $databaseName): string
    {
        $backupPath = $this->pluginSettings->getBackupPath();
        $backupDateTimeStamp = $this->pluginSettings->getDateTimestamp();
        $backupFileName = '';

        if($backupPath !== '') {
            $backupFileName = $backupPath.'/';
        }

        if($backupDateTimeStamp !== '') {
            $backupFileName .= $backupDateTimeStamp.'_';
        }

        if($databaseName !== '') {
            $backupFileName .= $databaseName;
        }

        if($this->pluginSettings->isCompressDbBackupEnabled()) {
            $backupFileName .= '.backup.sql.gz';
        } else {
            $backupFileName .= '.backup.sql';
        }

        return $backupFileName;
    }
}