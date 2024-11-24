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

class Defaults
{
    const BACKUP_PATH = '/var/backup';
    const BACKUP_FOLDER_PERMISSION = 0777;
    const CURRENT_DATETIME_STR_FORMAT = 'Y-m-d_H-i-s';
    const DEFAULT_LOGGER_CONFIG = array('wuwa', 'facility');
}