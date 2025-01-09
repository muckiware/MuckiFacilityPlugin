<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\TestCaseBase;
/**
 * Plugin wide default values
 */
final class Defaults
{
    public const DEFAULT_TEST_BACKUP_PATHS = array(
        array(
            'id' => '123123',
            'backupPath' => '/var/www/html/var/backup-1',
            'compress' => true,
            'position' => 0,
        ),
        array(
            'id' => '456456',
            'backupPath' => '/var/www/html/var/backup-2',
            'compress' => true,
            'position' => 0,
        )
    );

}
