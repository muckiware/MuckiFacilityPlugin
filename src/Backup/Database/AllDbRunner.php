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

use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;

class AllDbRunner implements BackupInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $settings
    ) {}
    public function createBackupData(): void
    {
        $mysqlDumper = MySqlDumper::create();
        $mysqlDumper->setDatabaseUrl($this->settings->getDatabaseUrl());

        $backupFileName = $this->settings->getBackupPath().'/'.$this->settings->getDateTimestamp().'_'.$mysqlDumper->getDbName().'.backup.sql';

        try {
            $mysqlDumper->dumpToFile($backupFileName);
        } catch (CannotStartDump $e) {
            $this->logger->error('Cannot start dump:'.$e->getMessage());
        } catch (DumpFailed $e) {
            $this->logger->error('Dump failed:'.$e->getMessage());
        }

        $checker = 1;
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
}