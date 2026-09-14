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
    public const DEFAULT_TEST_BACKUP_PATH = 'var/backup';
    public const TEST_WORKING_DIR_NAME = 'muwa-facility-tests';
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

    public const BACKUP_TEST_FILES = [
        'TEST file content 1',
        'TEST file content 2',
        'TEST file content 3'
    ];

    public const NEXT_BACKUP_TEST_FILES = [
        'TEST file content 4',
        'TEST file content 5',
        'TEST file content 6',
        'TEST file content 7',
        'TEST file content 8',
        'TEST file content 9'
    ];

    public static function getPluginPath(): string
    {
        return str_replace('/tests','', dirname(__DIR__));
    }

    public static function getResticPath(): string
    {
        return self::getPluginPath() . '/' . self::MUCKIWARE_RESTIC_BINARY_PATH;
    }

    /**
     * Base path for the repository, backup and restore directories of the integration tests.
     *
     * Deliberately outside the project directory: with Docker Desktop the project is mounted as a
     * FUSE share, and restic turns on O_NOATIME per fcntl(F_SETFL) after opening a source file.
     * On that mount the fcntl call succeeds but every following read() fails with EIO, so restic
     * cannot read any file to back up. The system temp dir is a regular filesystem without that
     * limitation. As a side effect the tests no longer leave restic repositories inside the plugin.
     */
    public static function getTestBasePath(): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/' . self::TEST_WORKING_DIR_NAME;
    }

    public static function getTestRepositoryPath(): string
    {
        return self::getTestBasePath() . '/' . self::DEFAULT_TEST_REPOSITORY_PATH;
    }

    public static function getTestRestorePath(): string
    {
        return self::getTestBasePath() . '/' . self::DEFAULT_TEST_RESTORE_PATH;
    }

    public static function getTestBackupPath(): string
    {
        return self::getTestBasePath() . '/' . self::DEFAULT_TEST_BACKUP_PATH;
    }
}
