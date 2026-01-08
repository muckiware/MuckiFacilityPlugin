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

use Psr\Log\LoggerInterface;

use MuckiRestic\Library\Manage;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;

class ManageRepository
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepository $backupRepository,
        protected BackupFileSnapshotsRepository $backupFileSnapshotsRepository,
        protected PluginSettings $pluginSettings
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
