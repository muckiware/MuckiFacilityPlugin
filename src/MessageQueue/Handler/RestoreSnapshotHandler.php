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
namespace MuckiFacilityPlugin\MessageQueue\Handler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\RestoreSnapshotMessage;
use MuckiFacilityPlugin\Services\AdminNotification;
use MuckiFacilityPlugin\Services\RestoreSnapshot as RestoreSnapshotServices;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiRestic\Exception\InvalidConfigurationException;

#[AsMessageHandler]
class RestoreSnapshotHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected RestoreSnapshotServices $restoreSnapshotServices,
        protected ServicesCliOutput $servicesCliOutput,
        protected BackupRepositoryService $backupRepositoryService,
        protected AdminNotification $adminNotification
    )
    {}

    /**
     * @throws InvalidConfigurationException
     */
    public function __invoke(RestoreSnapshotMessage $message): void
    {
        $this->logger->debug(
            'Restore process started. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );

        $this->servicesCliOutput->setIsCli(false);

        $backupRepository = $this->backupRepositoryService->getBackupRepositoryById($message->getBackupRepositoryId());
        $message->setRepositoryPassword($backupRepository->getRepositoryPassword());

        $context = Context::createDefaultContext();
        $parameters = [
            'name' => $backupRepository->getName(),
            'snapshot' => substr($message->getSnapshotId(), 0, 8),
        ];

        try {
            $this->restoreSnapshotServices->restoreSnapshot($message, false);
        } catch (\Throwable $exception) {

            $this->logger->error(
                'Restore process failed. BackupRepositoryId: '.$message->getBackupRepositoryId().'. Error: '.$exception->getMessage(),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            $this->notify(
                'error',
                'muwa-backup-repository.notification.restoreFailed',
                [...$parameters, 'error' => $exception->getMessage()],
                $context
            );
            throw $exception;
        }

        $this->notify('info', 'muwa-backup-repository.notification.restoreSucceeded', $parameters, $context);

        $this->logger->debug(
            'Restore process done. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function notify(string $status, string $snippet, array $parameters, Context $context): void
    {
        $message = json_encode([
            'source' => 'mucki-facility',
            'snippet' => $snippet,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->adminNotification->createNotification([
            'id' => Uuid::randomHex(),
            'status' => $status,
            'message' => $message,
            'adminOnly' => true,
            'requiredPrivileges' => ['muwa_backup_repository:restore'],
        ], $context);
    }
}
