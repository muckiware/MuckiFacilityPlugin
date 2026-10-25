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
namespace MuckiFacilityPlugin\Events;

use Symfony\Contracts\EventDispatcher\Event;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;

final class AfterSnapshotsDeletedEvent extends Event
{
    public const NAME = 'mucki_facility.snapshots.after_delete';

    /**
     * @param array<int, string> $snapshotIds
     */
    public function __construct(
        private readonly BackupRepositoryEntity $backupRepository,
        private readonly array $snapshotIds,
        private readonly string $output
    )
    {}

    public function getBackupRepository(): BackupRepositoryEntity
    {
        return $this->backupRepository;
    }

    /**
     * @return array<int, string>
     */
    public function getSnapshotIds(): array
    {
        return $this->snapshotIds;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
