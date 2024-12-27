<?php

namespace MuckiFacilityPlugin\MessageQueue\Handler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\Backup as BackupService;
use MuckiFacilityPlugin\Entity\BackupPathEntity;

#[AsMessageHandler]
class CreateBackupHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupService $backupService
    )
    {}
    public function __invoke(CreateBackupMessage $message)
    {
        $this->logger->debug(
            'Backup process started'.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
        $message->setBackupPaths($this->prepareBackupPaths($message->getBackupPaths()));
        $this->backupService->createBackup($message);
        $this->logger->debug(
            'Backup process done'.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }

    protected function prepareBackupPaths(array $backupPaths): array
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