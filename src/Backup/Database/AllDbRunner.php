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
use Spatie\DbDumper\Databases\MySql;
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
        $mysqlDumper = MySql::create();
        $mysqlDumper->setDatabaseUrl($this->settings->getDatabaseUrl());

        try {
            $mysqlDumper->dumpToFile(
                $this->settings->getBackupPath().'/'.$mysqlDumper->getDbName().'.backup.sql'
            );
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