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

final class AfterBackupCheckedEvent extends Event
{
    public const NAME = 'mucki_facility.backup.after_check';

    public function __construct(
        private readonly BackupRepositoryEntity $backupRepository,
        private readonly string $checkResult
    )
    {}

    public function getBackupRepository(): BackupRepositoryEntity
    {
        return $this->backupRepository;
    }

    public function getCheckResult(): string
    {
        return $this->checkResult;
    }
}
