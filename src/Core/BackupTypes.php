<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core;

enum BackupTypes: string
{
    case DATABASE_ALL = 'databaseAll';
    case DATABASE_SELECT_TABLE = 'databaseSelectTable';
    case FILES_ALL = 'filesAll';

    case MEDIA = 'media';
}