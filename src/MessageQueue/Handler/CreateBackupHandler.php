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
namespace MuckiFacilityPlugin\MessageQueue\Handler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Administration\Notification\NotificationService;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\Backup as BackupService;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

#[AsMessageHandler]
class CreateBackupHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        private readonly NotificationService $notificationService,
        protected BackupService $backupService,
        protected ServicesCliOutput $servicesCliOutput,
        protected BackupRepositoryService $backupRepositoryService
    )
    {}
    public function __invoke(CreateBackupMessage $message): void
    {
        $this->logger->debug(
            'Backup process started. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );

        $this->servicesCliOutput->setIsCli(false);
        $message->setBackupPaths($this->backupService->prepareBackupPaths($message->getBackupPaths()));
        $backupRepository = $this->backupRepositoryService->getBackupRepositoryById($message->getBackupRepositoryId());
        $message->setRepositoryPassword($backupRepository->getRepositoryPassword());
        $this->backupService->createBackup($message, false);

        $this->notificationService->createNotification(
            [
                'id' => Uuid::randomHex(),
                'status' => 'info',
                'message' => 'Create Backup is completed',
                'requiredPrivileges' => [],
            ],
            $message->getContext()
        );
        $this->logger->debug(
            'Backup process done. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }
}
