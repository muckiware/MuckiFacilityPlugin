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

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

final class BeforeBackupCreatedEvent extends Event
{
    public const NAME = 'mucki_facility.backup.before_create';

    public function __construct(
        private readonly BackupRepositorySettings $backupSettings
    )
    {}

    public function getBackupSettings(): BackupRepositorySettings
    {
        return $this->backupSettings;
    }
}
