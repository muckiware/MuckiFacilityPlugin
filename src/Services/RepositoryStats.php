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
namespace MuckiFacilityPlugin\Services;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryStats;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;

class RepositoryStats
{
    public function __construct(
        protected LoggerInterface $logger,
        protected ManageService $manageService,
        protected BackupRepository $backupRepository,
        protected BackupRepositoryChecks $backupRepositoryChecks,
        protected BackupRepositoryStats $backupRepositoryStats,
        protected PluginHelper $pluginHelper,
    )
    {}

    /**
     * Collects the current state of a backup repository. Every measured value is nullable:
     * `restic stats` does not always return all keys, and the directory scan can fail on its own.
     *
     * @return array{totalSize: int|null, totalFileCount: int|null, snapshotsCount: int|null, fileSystemSize: int|null, checkStatus: string|null}
     */
    public function collect(string $backupRepositoryId): array
    {
        $collected = [
            'totalSize' => null,
            'totalFileCount' => null,
            'snapshotsCount' => null,
            'fileSystemSize' => null,
            'checkStatus' => null,
        ];

        $resticStats = json_decode($this->manageService->getRepositoryStatsById($backupRepositoryId), true);
        if (is_array($resticStats)) {

            if (array_key_exists('total_size', $resticStats)) {
                $collected['totalSize'] = (int) $resticStats['total_size'];
            }

            if (array_key_exists('total_file_count', $resticStats)) {
                $collected['totalFileCount'] = (int) $resticStats['total_file_count'];
            }

            if (array_key_exists('snapshots_count', $resticStats)) {
                $collected['snapshotsCount'] = (int) $resticStats['snapshots_count'];
            }
        }

        $collected['fileSystemSize'] = $this->collectFileSystemSize($backupRepositoryId);

        $checks = $this->backupRepositoryChecks->getLatestChecksByRepositoryId($backupRepositoryId);
        if ($checks !== null) {
            $collected['checkStatus'] = $checks->getCheckStatus();
        }

        return $collected;
    }

    /**
     * Collects and persists the current state. Never throws: a failed status must not turn a
     * successful backup run into a failed one.
     *
     * @return array{totalSize: int|null, totalFileCount: int|null, snapshotsCount: int|null, fileSystemSize: int|null, checkStatus: string|null}|null the collected data on success, null when a throwable was swallowed
     */
    public function collectAndSave(string $backupRepositoryId): ?array
    {
        try {
            $collected = $this->collect($backupRepositoryId);
            $this->backupRepositoryStats->saveNewStats($backupRepositoryId, $collected);

            return $collected;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }

    /**
     * Same as collectAndSave(), but returns the id of the saved stats entry, so the backup run can
     * add its duration once it is completely finished. Never throws.
     */
    public function collectAndSaveForBackup(string $backupRepositoryId): ?string
    {
        try {
            return $this->backupRepositoryStats->saveNewStats($backupRepositoryId, $this->collect($backupRepositoryId));
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }

    /**
     * Persists the runtime of a backup run, successful or not. Updates the stats entry of the run
     * if there is one, otherwise (run aborted before its stats were saved) saves a new entry with the
     * current state, falling back to empty values if even that cannot be collected. Never throws.
     */
    public function saveBackupDuration(string $backupRepositoryId, ?string $statsId, int $backupDuration): void
    {
        try {
            if ($statsId !== null) {
                $this->backupRepositoryStats->updateBackupDuration($statsId, $backupDuration);
                return;
            }

            $collected = $this->collectOrEmpty($backupRepositoryId);
            $collected['backupDuration'] = $backupDuration;
            $this->backupRepositoryStats->saveNewStats($backupRepositoryId, $collected);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Backup duration could not be saved: '.$e->getMessage(),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }
    }

    /**
     * @return array{totalSize: int|null, totalFileCount: int|null, snapshotsCount: int|null, fileSystemSize: int|null, checkStatus: string|null}
     */
    protected function collectOrEmpty(string $backupRepositoryId): array
    {
        try {
            return $this->collect($backupRepositoryId);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return [
            'totalSize' => null,
            'totalFileCount' => null,
            'snapshotsCount' => null,
            'fileSystemSize' => null,
            'checkStatus' => null,
        ];
    }

    protected function collectFileSystemSize(string $backupRepositoryId): ?int
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        if ($backupRepository === null) {
            return null;
        }

        try {
            return $this->pluginHelper->getDirectorySize($backupRepository->getRepositoryPath());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }
}
