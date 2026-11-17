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

/**
 * Carries ids only, never credentials.
 */
class MirrorRepositoryMessage implements AsyncMessageInterface
{
    public function __construct(
        protected string $backupRepositoryId,
        protected ?string $checkId
    )
    {}

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function getCheckId(): ?string
    {
        return $this->checkId;
    }
}
