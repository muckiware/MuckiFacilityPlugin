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
namespace MuckiFacilityPlugin\Backup\Database;

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Entity\DatabaseBackupTarget;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;

class CompleteFileRunner implements BackupInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $pluginSettings,
        protected BackupRepositorySettings $createBackup,
        protected DatabaseBackupTarget $target,
        protected DumpArchiver $dumpArchiver
    ) {}
    /**
     * @throws DatabaseBackupFailedException for a configured connection, the default connection only logs
     */
    public function createBackupData(bool $isJsonOutput=true): void
    {
        $connection = $this->target->getConnection();

        try {
            $dumpFile = $this->createBackupFileName($connection->getDatabaseName());
            $this->target->getDumper()->dump($connection, $dumpFile);

            if($this->pluginSettings->isCompressDbBackupEnabled()) {
                $this->dumpArchiver->archive($dumpFile);
            }
        } catch (DatabaseBackupFailedException $e) {
            if(!$this->target->isDefault()) {
                throw new DatabaseBackupFailedException(sprintf(
                    'Database backup for connection "%s" failed: %s',
                    $this->target->getConnectionName(),
                    $e->getMessage()
                ), 0, $e);
            }
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
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

    public function checkBackupData(): void
    {
        // TODO: Implement checkBackupData() method.
    }

    public function getBackupResults(): array
    {
        // TODO: Implement getBackupResult() method.
        return array();
    }

    public function createBackupFileName(string $databaseName, bool $useSubFolder=false): string
    {
        $backupPath = $this->pluginSettings->getBackupPath(
            $useSubFolder,
            $this->createBackup->getDbDumpPath(),
            $this->createBackup->hasRepositoryPath() ? $this->createBackup->getRepositoryPath() : null,
            $this->createBackup->hasRestorePath() ? $this->createBackup->getRestorePath() : null
        );
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

        return $backupFileName.'.backup.sql';
    }
}
