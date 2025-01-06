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
namespace MuckiFacilityPlugin\Services;

use MuckiRestic\Entity\Result\ResultEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Output\OutputInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\ConfigPath;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class Backup
{
    protected array $allResults = [];
    protected BackupInterface $backup;
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRunnerFactory $backupRunnerFactory,
        protected BackupRepository $backupRepository,
        protected BackupRepositoryChecks $backupRepositoryChecks,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
        protected ManageService $manageService,
        protected ServicesCliOutput $servicesCliOutput
    )
    {}

    public function getBackup(): BackupInterface
    {
        return $this->backup;
    }

    public function setBackup(BackupInterface $backup): void
    {
        $this->backup = $backup;
    }

    public function getAllResults(): array
    {
        return $this->allResults;
    }

    public function addAllResult(array $allResults): void
    {
        $this->allResults = array_merge($this->allResults, $allResults);
    }

    public function createBackup(CreateBackupEntity $createBackup, bool $isJsonOutput=true): void
    {
        $cachePaths = $createBackup->getBackupPaths();

        //Backup database
        if($createBackup->getBackupType() !== BackupTypes::NONE_DATABASE->value) {
            $this->runDatabaseBackup($createBackup, $isJsonOutput);
        }

        //Backup files
        if(!empty($createBackup->getBackupPaths())) {
            $this->runFilesBackup($createBackup, $cachePaths, $isJsonOutput);
        }

        $this->createCheckItem($createBackup);
        $this->manageService->saveSnapshots($createBackup->getBackupRepositoryId());
    }

    public function createDump(CreateBackupEntity $createBackup): void
    {
        $this->startBackupRunner($createBackup, false);
    }

    public function runDatabaseBackup(CreateBackupEntity $createBackup, bool $isJsonOutput=true): void
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('run backup database...');
        }

        $this->pluginHelper->deleteDirectory($this->pluginSettings->getBackupPath());

        $backupPath = new BackupPathEntity();
        $backupPath->setBackupPath($this->pluginSettings->getBackupPath());
        $backupPath->setPosition(0);
        $backupPath->setCompress($this->pluginSettings->isCompressDbBackupEnabled());
        $backupPath->setIsDefault(false);

        $createBackup->setBackupPaths(array($backupPath));

        //create database dump
        $this->startBackupRunner($createBackup, $isJsonOutput);

        //write dump into backup repository
        $createBackup->setBackupType(BackupTypes::FILES->value);
        $this->startBackupRunner($createBackup, $isJsonOutput);

        $this->pluginHelper->deleteDirectory($this->pluginSettings->getBackupPath());
    }

    public function runFilesBackup(CreateBackupEntity $createBackup, $cachePaths, bool $isJsonOutput=true): void
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('run backup files...');
        }

        $createBackup->setBackupType(BackupTypes::FILES->value);
        $createBackup->setBackupPaths($cachePaths);

        $this->startBackupRunner($createBackup, $isJsonOutput);
    }

    public function checkBackup(CreateBackupEntity $createBackup): void
    {
        $createBackup = clone $createBackup;
        $createBackup->setBackupType(BackupTypes::FILES->value);
        try {
            $backupRunner = $this->backupRunnerFactory->createBackupRunner($createBackup);
            $backupRunner->checkBackupData();

            $this->addAllResult($backupRunner->getBackupResults());
            $this->setBackup($backupRunner);

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }

    public function startBackupRunner(CreateBackupEntity $createBackup, bool $isJsonOutput): void
    {
        try {
            $backupRunner = $this->backupRunnerFactory->createBackupRunner($createBackup);
            $backupRunner->createBackupData($isJsonOutput);

            $this->addAllResult($backupRunner->getBackupResults());
            $this->setBackup($backupRunner);

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }

    public function createCheckItem(CreateBackupEntity $createBackup): void
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('Check backup...');
        }
        $this->checkBackup($createBackup);

        /** @var ResultEntity $result */
        foreach ($this->getAllResults() as $result) {

            $checkResults = $this->pluginHelper->getCheckResults($result->getOutput());
            $this->backupRepositoryChecks->saveNewCheck($createBackup->getBackupRepositoryId(), end($checkResults));
        }
    }

    public function prepareBackupPaths(array $backupPaths): array
    {
        $preparedBackupPaths = [];
        foreach ($backupPaths as $backupPath) {

            $backupPathEntity = new BackupPathEntity();
            $backupPathEntity->setId($backupPath['id']);
            $backupPathEntity->setBackupPath($backupPath['backupPath']);
            $backupPathEntity->setCompress($backupPath['compress']);
            $backupPathEntity->setPosition($backupPath['position']);

            $preparedBackupPaths[] = $backupPathEntity;
        }

        return $preparedBackupPaths;
    }

    public function prepareCreateBackup(string $backupRepositoryId): CreateBackupEntity
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('Prepare backup');
        }

        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        $createBackup = new CreateBackupEntity();

        if($backupRepository) {

            $createBackup->setBackupRepositoryId($backupRepositoryId);
            $createBackup->setBackupType($backupRepository->getType());
            $createBackup->setBackupPaths($this->prepareBackupPaths($backupRepository->getBackupPaths()));
            $createBackup->setRepositoryPath($backupRepository->getRepositoryPath());
            $createBackup->setRepositoryPassword($backupRepository->getRepositoryPassword());
        }

        return $createBackup;
    }

    public function prepareCheckBackup(string $backupRepositoryId): CreateBackupEntity
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('Prepare checkup');
        }

        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        $createBackup = new CreateBackupEntity();

        if($backupRepository) {

            $createBackup->setBackupType(BackupTypes::FILES->value);
            $createBackup->setRepositoryPath($backupRepository->getRepositoryPath());
            $createBackup->setRepositoryPassword($backupRepository->getRepositoryPassword());
        } else {

            if($this->servicesCliOutput->isCli()) {

                $this->servicesCliOutput->printCliOutputNewline(
                    'Missing backup repository for id: '.$backupRepositoryId
                );
            }
        }

        return $createBackup;
    }

    public function prepareDbBackupFileName(string $databaseName, bool $useSubFolder=false): string
    {
        $backupPath = $this->pluginSettings->getBackupPath($useSubFolder);
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
