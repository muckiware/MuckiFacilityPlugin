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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\UpdateRepositoryStatsMessage;
use MuckiFacilityPlugin\Services\RepositoryStats;

#[AsMessageHandler]
class UpdateRepositoryStatsHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected RepositoryStats $repositoryStats
    )
    {}

    public function __invoke(UpdateRepositoryStatsMessage $message): void
    {
        $this->logger->debug(
            'Repository stats update started. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );

        $this->repositoryStats->collectAndSave($message->getBackupRepositoryId());

        $this->logger->debug(
            'Repository stats update done. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }
}
