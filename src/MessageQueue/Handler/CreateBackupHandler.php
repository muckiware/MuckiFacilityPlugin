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

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryInactiveException;
#[AsMessageHandler]
final class CreateBackupHandler
{
    public function __construct(private readonly BackupExecution $execution) {}
    public function __invoke(CreateBackupMessage $message): void
    {
        try {
            $this->execution->execute($message->repositoryId);
        } catch (BackupRepositoryInactiveException) {
            // deactivated after the message was queued, retrying would not change anything
        }
    }
}
