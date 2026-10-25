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

final class AfterSnapshotDeletedEvent extends Event
{
    public const NAME = 'mucki_facility.snapshot.after_delete';

    public function __construct(
        private readonly string $backupRepositoryId,
        private readonly string $snapshotId,
        private readonly string $output
    )
    {}

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function getSnapshotId(): string
    {
        return $this->snapshotId;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
