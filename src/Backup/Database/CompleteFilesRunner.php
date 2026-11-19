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

class CompleteFilesRunner implements BackupInterface, DumpFailureAwareInterface
{
    /**
     * @var array<int, string>
     */
    protected array $dumpFailures = [];

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
        $dumper = $this->target->getDumper();
        $isCompressDbBackupEnabled = $this->pluginSettings->isCompressDbBackupEnabled();
        $this->dumpFailures = [];

        try {
            $tables = $dumper->listTables($connection);
        } catch (DatabaseBackupFailedException $e) {
            $this->handleFailure($e);
            return;
        }

        // The default connection's table list swallows database errors and returns nothing; a backup
        // without a single table must never pass as a successful database dump.
        if ($tables === []) {
            $this->handleFailure(new DatabaseBackupFailedException('Cannot list tables: no tables found in the database'));
            return;
        }

        foreach ($tables as $table) {
            try {
                $dumpFile = $this->createBackupFileName($table, true);
                $dumper->dump($connection, $dumpFile, [$table]);

                if($isCompressDbBackupEnabled) {
                    $this->dumpArchiver->archive($dumpFile);
                }
            } catch (DatabaseBackupFailedException $e) {
                $this->handleFailure($e);
            }
        }
    }

    /**
     * Default connection: log, remember for Services\Backup and go on. Own connection: abort the backup run.
     *
     * @throws DatabaseBackupFailedException
     */
    protected function handleFailure(DatabaseBackupFailedException $e): void
    {
        if(!$this->target->isDefault()) {
            throw new DatabaseBackupFailedException(sprintf(
                'Database backup for connection "%s" failed: %s',
                $this->target->getConnectionName(),
                $e->getMessage()
            ), 0, $e);
        }
        $this->dumpFailures[] = $e->getMessage();
        $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
    }

    public function getDumpFailures(): array
    {
        return $this->dumpFailures;
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
