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
namespace MuckiFacilityPlugin\Core;

class Defaults
{
    const DATABASE_BACKUP_PATH = '/var/db/backup';
    const BACKUP_FOLDER_PERMISSION = 0777;
    const CURRENT_DATETIME_STR_FORMAT = 'Y-m-d_H-i-s';
    const CURRENT_DATE_STR_FORMAT = 'Y-m-d';
    const DEFAULT_LOGGER_CONFIG = array('muwa', 'facility');
}