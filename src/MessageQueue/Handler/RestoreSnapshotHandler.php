<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\MessageQueue\Handler;

use MuckiRestic\Exception\InvalidConfigurationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\RestoreSnapshot as RestoreSnapshotServices;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

#[AsMessageHandler]
class RestoreSnapshotHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected RestoreSnapshotServices $restoreSnapshotServices,
        protected ServicesCliOutput $servicesCliOutput,
        protected BackupRepositoryService $backupRepositoryService
    )
    {}

    /**
     * @throws InvalidConfigurationException
     */
    public function __invoke(CreateBackupMessage $message): void
    {
        $this->logger->debug(
            'Backup process started. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );

        $this->servicesCliOutput->setIsCli(false);

        $backupRepository = $this->backupRepositoryService->getBackupRepositoryById($message->getBackupRepositoryId());
        $message->setRepositoryPassword($backupRepository->getRepositoryPassword());
        $this->restoreSnapshotServices->restoreSnapshot($message, false);

        $this->logger->debug(
            'Backup process done. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }
}
