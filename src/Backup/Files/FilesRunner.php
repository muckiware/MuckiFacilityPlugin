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
namespace MuckiFacilityPlugin\Backup\Files;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Symfony\Component\Console\Helper\ProgressBar;

use MuckiRestic\Library\Backup;
use MuckiRestic\Entity\Result\ResultEntity;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiRestic\Exception\InvalidConfigurationException;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class FilesRunner implements BackupInterface
{
    /**
     * @var array<ResultEntity> $backupResultsq
     */
    protected array $backupResults;
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected BackupRepositorySettings $createBackup,
        protected ServicesCliOutput $servicesCliOutput
    ) {}

    public function getBackupResults(): array
    {
        return $this->backupResults;
    }

    public function addBackupResult(ResultEntity $backupResult): void
    {
        $this->backupResults[] = $backupResult;
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function createBackupData(bool $isJsonOutput=true): void
    {
        $progress = null;
        $progressBar = null;

        $backupClient = $this->prepareBackupClient($isJsonOutput);

        if(!empty($this->createBackup->getBackupPaths())) {

            $totalCounter = count($this->createBackup->getBackupPaths());

            if($this->servicesCliOutput->isCli()) {

                $progress = $this->servicesCliOutput->prepareProgress($totalCounter);
                $progressBar = $this->servicesCliOutput->prepareProgressBar($progress, $totalCounter);
                $this->servicesCliOutput->setProgressMessage('Paths');
            }

            /** @var BackupPathEntity $backupPath */
            foreach ($this->createBackup->getBackupPaths() as $backupPath) {

                $this->setProgressStatus($progress, $progressBar, $backupPath->getBackupPath());

                $backupClient->setBackupPath($backupPath->getBackupPath());
                if($backupPath->isCompress()) {
                    $backupClient->setCompress(true);
                }

                $createBackup = $backupClient->createBackup();
                $this->addBackupResult($createBackup);
                $this->logger->info($createBackup->getOutput(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            }
        }
    }

    public function setProgressStatus(?Progress $progress, ?ProgressBar $progressBar)
    {
        if ($this->servicesCliOutput->isCli() && $progress && $progressBar) {

            if ($progress->getOffset() >= $progress->getTotal()) {
                $progressBar->setProgress($progress->getTotal());
            } else {
                $progressBar->advance();
                $progressBar->display();
            }
        }
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function checkBackupData(): void
    {
        $backupClient = $this->prepareBackupClient();
        $checkBackup = $backupClient->checkBackup();
        $this->addBackupResult($checkBackup);

        $this->logger->info($checkBackup->getOutput(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
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

    public function prepareBackupClient(bool $isJsonOutput=true): Backup
    {
        $backupClient = Backup::create();
        if($this->pluginSettings->hasOwnResticBinaryPath()) {
            $backupClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
        }

        $backupClient->setRepositoryPassword($this->createBackup->getRepositoryPassword());
        $backupClient->setRepositoryPath($this->createBackup->getRepositoryPath());
        $backupClient->setJsonOutput($isJsonOutput);

        return $backupClient;
    }
}
