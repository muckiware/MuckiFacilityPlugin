<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 */
namespace MuckiFacilityPlugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Services\BackupCommandExecutor;

class BackupCommandSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BackupCommandExecutor $backupCommandExecutor
    )
    {}

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeBackupCreatedEvent::NAME => 'onBeforeBackupCreated',
            AfterBackupCreatedEvent::NAME => 'onAfterBackupCreated',
        ];
    }

    public function onBeforeBackupCreated(BeforeBackupCreatedEvent $event): void
    {
        $settings = $event->getBackupSettings();

        $this->backupCommandExecutor->execute(
            $settings->getPreBackupCommandType(),
            $settings->getPreBackupCommand(),
            $settings->isPreBackupFailOnError()
        );
    }

    public function onAfterBackupCreated(AfterBackupCreatedEvent $event): void
    {
        $settings = $event->getBackupSettings();

        $this->backupCommandExecutor->execute(
            $settings->getPostBackupCommandType(),
            $settings->getPostBackupCommand(),
            $settings->isPostBackupFailOnError()
        );
    }
}
