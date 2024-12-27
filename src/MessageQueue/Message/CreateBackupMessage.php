<?php

namespace MuckiFacilityPlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class CreateBackupMessage implements AsyncMessageInterface
{
    private string $backupRepositoryId;

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function setBackupRepositoryId(string $backupRepositoryId): void
    {
        $this->backupRepositoryId = $backupRepositoryId;
    }
}
