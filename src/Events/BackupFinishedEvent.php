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

use MuckiRestic\Entity\Result\ResultEntity;
use Symfony\Contracts\EventDispatcher\Event;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

final class BackupFinishedEvent extends Event
{
    public const NAME = 'mucki_facility.backup.finished';

    /**
     * @param array<ResultEntity> $results
     */
    public function __construct(
        private readonly BackupRepositorySettings $backupSettings,
        private readonly array $results,
        private readonly ?\Throwable $primaryError,
        private readonly bool $coreSucceeded
    )
    {}

    public function getBackupSettings(): BackupRepositorySettings
    {
        return $this->backupSettings;
    }

    /**
     * @return array<ResultEntity>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getPrimaryError(): ?\Throwable
    {
        return $this->primaryError;
    }

    public function isCoreSucceeded(): bool
    {
        return $this->coreSucceeded;
    }
}
