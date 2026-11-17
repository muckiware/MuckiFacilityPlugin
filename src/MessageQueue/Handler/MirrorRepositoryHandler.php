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
use MuckiFacilityPlugin\MessageQueue\Message\MirrorRepositoryMessage;
use MuckiFacilityPlugin\Services\RepositoryMirror;

#[AsMessageHandler]
class MirrorRepositoryHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected RepositoryMirror $repositoryMirror
    )
    {}

    /**
     * Mirrors the repository after a backup. Never throws, so the messenger does not redeliver the message.
     */
    public function __invoke(MirrorRepositoryMessage $message): void
    {
        try {
            $this->repositoryMirror->mirror($message->getBackupRepositoryId(), RepositoryMirror::TRIGGER_BACKUP, $message->getCheckId());
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Repository mirror failed. BackupRepositoryId: '.$message->getBackupRepositoryId().', '.$exception->getMessage(),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }
    }
}
