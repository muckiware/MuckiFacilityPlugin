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
namespace MuckiFacilityPlugin\Events;

class BackupRepositorySnapshotsEvents
{
    final public const BACKUP_REPOSITORY_SNAPSHOT_WRITTEN_EVENT = 'muwa_backup_repository_snapshots.written';
    final public const BACKUP_REPOSITORY_SNAPSHOT_DELETED_EVENT = 'muwa_backup_repository_snapshots.deleted';
    final public const BACKUP_REPOSITORY_SNAPSHOT_LOADED_EVENT = 'muwa_backup_repository_snapshots.loaded';
}