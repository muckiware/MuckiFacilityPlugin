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

    public const MEDIA_TEST_FILE_1 = [
        'url' => 'https://example.com/media/4f/0e/87/1711356804/waschmaschine_600x600.jpg',
        'path' => 'media/4f/0e/87/1711356804/waschmaschine_600x600.jpg',
        'title' => 'Waschmaschine',
        'alt' => 'Waschmaschine 600x600',
        'fileName' => 'waschmaschine_600x600.jpg',
        'mimeType' => 'image/jpeg',
        'fileExtension' => 'jpg',
    ];
    public const MEDIA_TEST_FILE_2 = [
        'url' => 'https://example.com/media/4f/0e/87/1711356804/shirt_red_600x600.jpg',
        'path' => 'media/4f/0e/87/1711356804/shirt_red_600x600.jpg',
        'title' => 'Shirt Red',
        'alt' => 'Shirt Red 600x600',
        'fileName' => 'shirt_red_600x600.jpg',
        'mimeType' => 'image/jpeg',
        'fileExtension' => 'jpg',
    ];

    public const ENCODE_WEBP_URL = 'https://sw66.ddev.site/thumbnail/25/fe/cc/1711356804/hq_1280x1280_1920x1920.jpg?ts=1729685240';

    public static function getPluginPath(): string
    {
        return str_replace('/tests','', dirname(__DIR__));
    }

    public static function getResticPath(): string
    {
        return self::getPluginPath() . '/' . self::MUCKIWARE_RESTIC_BINARY_PATH;
    }
}
