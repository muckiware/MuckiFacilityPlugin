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
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository;

class Backup
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRunnerFactory $backupRunnerFactory,
        protected BackupRepository $backupRepository
    )
    {}

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

    public function createBackup(CreateBackupEntity $createBackup): void
    {
        try {
            $backupRunner = $this->backupRunnerFactory->createBackupRunner($createBackup);
            $backupRunner->createBackupData();
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
}
