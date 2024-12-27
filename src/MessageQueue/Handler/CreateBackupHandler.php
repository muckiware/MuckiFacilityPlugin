<?php

namespace MuckiFacilityPlugin\MessageQueue\Handler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;

#[AsMessageHandler]
class CreateBackupHandler
{
    public function __construct(
        protected LoggerInterface $logger,
    )
    {}
    public function __invoke(CreateBackupMessage $message)
    {
        $this->logger->debug(
            'Backup process started'.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }
}