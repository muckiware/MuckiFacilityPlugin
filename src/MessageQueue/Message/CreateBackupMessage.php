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
namespace MuckiFacilityPlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\Framework\Context;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

class CreateBackupMessage extends BackupRepositorySettings implements AsyncMessageInterface
{
    public function __construct(
        protected string $backupRepositoryId,
        protected string $backupName,
        private readonly Context $context
    ) {
    }

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function getBackupName(): string
    {
        return $this->backupName;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
