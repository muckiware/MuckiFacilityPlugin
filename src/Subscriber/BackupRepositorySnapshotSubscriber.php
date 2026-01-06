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
namespace MuckiFacilityPlugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;

use MuckiFacilityPlugin\Events\BackupRepositorySnapshotsEvents;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Services\ManageRepository;

class BackupRepositorySnapshotSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected BackupRepositoryService $backupRepositoryService
    )
    {}

    public static function getSubscribedEvents(): array {

        return [
            BackupRepositorySnapshotsEvents::BACKUP_REPOSITORY_SNAPSHOT_DELETED_EVENT => 'onBackupRepositorySnapshotDeleted'
        ];
    }

    public function onBackupRepositorySnapshotDeleted(EntityDeletedEvent $event): void
    {
//        foreach ($event->getWriteResults() as $writeResult) {
//
//            $backupRepositoryId = $writeResult->getPrimaryKey();
//            if(is_string($backupRepositoryId)) {
//
//
//                $backupRepository = $this->backupRepositoryService->getBackupRepositoryById($writeResult->getPrimaryKey());
//            }
//
//            $checker =0;
//        }
//        $checker =1;
    }
}
