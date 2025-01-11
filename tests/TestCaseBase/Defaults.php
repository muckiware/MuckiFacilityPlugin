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
    public const DEFAULT_TEST_REPOSITORY_PATH = 'var/repository';
    public const DEFAULT_TEST_RESTORE_PATH = 'var/restore';
    public const MUCKIWARE_RESTIC_BINARY_PATH = 'bin/restic_0.17.3_linux_386';
    public const DEFAULT_TEST_REPOSITORY_PASSWORD = '123456';
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

    public static function getPluginPath(): string
    {
        return str_replace('/tests','', dirname(__DIR__));
    }

    public static function getResticPath(): string
    {
        return self::getPluginPath() . '/' . self::MUCKIWARE_RESTIC_BINARY_PATH;
    }
}
