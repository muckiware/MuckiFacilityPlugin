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

final class AfterDatabaseDumpCreatedEvent extends Event
{
    public const NAME = 'mucki_facility.database_dump.after_create';

    /**
     * @param array<ResultEntity> $results
     */
    public function __construct(
        private readonly BackupRepositorySettings $backupSettings,
        private readonly array $results
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
}
