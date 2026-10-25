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

final class AfterRepositoryCleanedUpEvent extends Event
{
    public const NAME = 'mucki_facility.repository.after_cleanup';

    public function __construct(
        private readonly BackupRepositoryEntity $backupRepository,
        private readonly string $output
    )
    {}

    public function getBackupRepository(): BackupRepositoryEntity
    {
        return $this->backupRepository;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
