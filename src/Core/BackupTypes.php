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
    case COMPLETE_DATABASE_SINGLE_FILE = 'completeDatabaseSingleFile';
    case COMPLETE_DATABASE_SEPARATE_FILES = 'completeDatabaseSeparateFiles';
    case FILES = 'files';
    case NONE_DATABASE = 'noneDatabase';
}