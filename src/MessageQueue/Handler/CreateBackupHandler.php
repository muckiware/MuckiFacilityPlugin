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

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\Backup as BackupService;

#[AsMessageHandler]
class CreateBackupHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupService $backupService
    )
    {}
    public function __invoke(CreateBackupMessage $message): void
    {
        $this->logger->debug(
            'Backup process started'.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
        $message->setBackupPaths($this->backupService->prepareBackupPaths($message->getBackupPaths()));
        $this->backupService->createBackup($message);
        $this->logger->debug(
            'Backup process done'.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }
}
