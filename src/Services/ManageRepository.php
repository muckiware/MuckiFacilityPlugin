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
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiRestic\Library\Manage;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;

class ManageRepository
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepository $backupRepository,
        protected BackupFileSnapshotsRepository $backupFileSnapshotsRepository,
        protected BackupRepositoryChecks $backupRepositoryChecks,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
    )
    {}

    public function getSnapshots(string $backupRepositoryId, bool $isJsonOutput=true): string
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        try {

            $manageClient = Manage::create();
            if($this->pluginSettings->hasOwnResticBinaryPath()) {
                $manageClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
            }
            $manageClient->setRepositoryPath($backupRepository->getRepositoryPath());
            $manageClient->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $manageClient->setJsonOutput($isJsonOutput);

            return $manageClient->getSnapshots()->getOutput();

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return '';
    }

    public function saveSnapshots(string $backupRepositoryId): void
    {
        $fileSnapshots = json_decode($this->getSnapshots($backupRepositoryId), true);

        $this->backupFileSnapshotsRepository->removeOldSnapshots($backupRepositoryId);
        $this->backupFileSnapshotsRepository->createNewSnapshots($backupRepositoryId, $fileSnapshots);
    }

    public function removeSnapshotByIds(string $backupRepositoryId, array $snapshotIds, bool $isJsonOutput=true): string
    {
        $result = [];
        foreach ($snapshotIds as $snapshotId) {
            $result[] = $this->removeSnapshotById($backupRepositoryId, $snapshotId, $isJsonOutput);
        }

        return implode("\n", $result);
    }

    public function removeSnapshotById(string $backupRepositoryId, string $snapshotId, bool $isJsonOutput=true): string
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        try {

            $manageClient = Manage::create();
            if($this->pluginSettings->hasOwnResticBinaryPath()) {
                $manageClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
            }
            $manageClient->setRepositoryPath($backupRepository->getRepositoryPath());
            $manageClient->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $manageClient->setJsonOutput($isJsonOutput);
            $manageClient->setSnapshotId($snapshotId);

            return $manageClient->removeSnapshotById()->getOutput();

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return '';
    }

    public function getRepositoryStatsById(string $backupRepositoryId, bool $isJsonOutput=true): string
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        try {

            $manageClient = Manage::create();
            if($this->pluginSettings->hasOwnResticBinaryPath()) {
                $manageClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
            }
            $manageClient->setRepositoryPath($backupRepository->getRepositoryPath());
            $manageClient->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $manageClient->setJsonOutput($isJsonOutput);

            return $manageClient->getRepositoryStats()->getOutput();

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return '';
    }

    public function generateStatsOutputs(array $repositoryStats, string $backupRepositoryId): array
    {
        $directorySize = 0;
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        $statsOutputs = array();

        try {
            $directorySize = $this->pluginHelper->getDirectorySize($backupRepository->getRepositoryPath());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        $statsOutputs['totalFileSystemSize'] = array (
            'id' => Uuid::randomHex(),
            'label' => 'muwa-backup-repository.list.totalFileSystemSizeLabel',
            'value' => \ByteUnits\Binary::bytes($directorySize)->asMetric()->format()
        );

        if(array_key_exists('total_size', $repositoryStats)) {

            $statsOutputs['totalFileRepositorySize'] = array (
                'id' => Uuid::randomHex(),
                'label' => 'muwa-backup-repository.list.totalFileRepositorySizeLabel',
                'value' => \ByteUnits\Binary::bytes($repositoryStats['total_size'])->asMetric()->format()
            );
        }

        if(array_key_exists('snapshots_count', $repositoryStats)) {

            $statsOutputs['totalSnapshots'] = array (
                'id' => Uuid::randomHex(),
                'label' => 'muwa-backup-repository.list.totalSnapshotsLabel',
                'value' => $repositoryStats['snapshots_count']
            );
        }

        if(array_key_exists('total_file_count', $repositoryStats)) {

            $statsOutputs['totalFiles'] = array (
                'id' => Uuid::randomHex(),
                'label' => 'muwa-backup-repository.list.totalFilesLabel',
                'value' => $repositoryStats['total_file_count']
            );
        }

        $checks = $this->backupRepositoryChecks->getLatestChecksByRepositoryId($backupRepositoryId);
        if($checks) {

            $statsOutputs['checkStatus'] = array (
                'id' => Uuid::randomHex(),
                'label' => 'muwa-backup-repository.list.CheckStatusLabel',
                'value' => $checks->getCheckStatus()
            );
        }

        return $statsOutputs;
    }

    public function cleanupRepository(string $backupRepositoryId, bool $isJsonOutput=true): string
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        try {

            $manageClient = Manage::create();
            if($this->pluginSettings->hasOwnResticBinaryPath()) {
                $manageClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
            }
            $manageClient->setRepositoryPath($backupRepository->getRepositoryPath());
            $manageClient->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $manageClient->setJsonOutput($isJsonOutput);

            return $manageClient->executePrune()->getOutput();

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return '';
    }

    public function forgetSnapshots(string $backupRepositoryId, bool $isJsonOutput=true): string
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        try {

            $manageClient = Manage::create();
            if($this->pluginSettings->hasOwnResticBinaryPath()) {
                $manageClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
            }
            $manageClient->setRepositoryPath($backupRepository->getRepositoryPath());
            $manageClient->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $manageClient->setJsonOutput($isJsonOutput);
            $manageClient->setKeepDaily($backupRepository->getForgetDaily());
            $manageClient->setKeepWeekly($backupRepository->getForgetWeekly());
            $manageClient->setKeepMonthly($backupRepository->getForgetMonthly());
            $manageClient->setKeepYearly($backupRepository->getForgetYearly());

            return $manageClient->removeSnapshots()->getOutput();

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return '';
    }
}
