<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Schedule\Task;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class DispatchBackupSchedulesTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'mucki_facility.dispatch_backup_schedules';
    }

    public static function getDefaultInterval(): int
    {
        return 60;
    }
}
