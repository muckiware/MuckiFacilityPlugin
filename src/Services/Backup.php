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

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\ConfigPath;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;

class Backup
{
    protected array $allResults = [];
    protected BackupInterface $backup;
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRunnerFactory $backupRunnerFactory,
        protected BackupRepository $backupRepository,
        protected PluginSettings $pluginSettings
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
        if($createBackup->getBackupType() !== BackupTypes::NONE_DATABASE->value) {

            $backupPath = new BackupPathEntity();
            $backupPath->setBackupPath($this->pluginSettings->getBackupPath());
            $backupPath->setPosition(0);
            $backupPath->setCompress($this->pluginSettings->isCompressDbBackupEnabled());
            $backupPath->setIsDefault(false);

            $createBackup->setBackupPaths(array($backupPath));
            $this->startBackupRunner($createBackup, $isJsonOutput);
        }

        if(!empty($createBackup->getBackupPaths())) {

            $createBackup->setBackupType(BackupTypes::FILES->value);
            $this->startBackupRunner($createBackup, $isJsonOutput);
        }
    }

    public function checkBackup(CreateBackupEntity $createBackup): void
    {
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

    public function prepareCreateBackup(string $backupRepositoryId, OutputInterface $output): CreateBackupEntity
    {
        $output->writeln('Prepare backup');
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        $createBackup = new CreateBackupEntity();

        if($backupRepository) {

            $createBackup->setBackupType($backupRepository->getType());
            $createBackup->setBackupPaths($this->prepareBackupPaths($backupRepository->getBackupPaths()));
            $createBackup->setRepositoryPath($backupRepository->getRepositoryPath());
            $createBackup->setRepositoryPassword($backupRepository->getRepositoryPassword());
        } else {
            $output->writeln('Prepare backup');
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
