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

enum ConfigPath: string
{
    case CONFIG_PATH_ACTIVE = 'MuckiFacilityPlugin.config.active';
    case CONFIG_PATH_COMPRESS_DB_BACKUP = 'MuckiFacilityPlugin.config.compressDbBackup';

    case CONFIG_USE_OWN_PATH_RESTIC_BINARY = 'MuckiFacilityPlugin.config.useOwnResticPath';
    case CONFIG_OWN_PATH_RESTIC_BINARY = 'MuckiFacilityPlugin.config.ownPathResticBinary';

    case CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_CART = 'LightsOn.Library.config.numberOfValidDaysInCart';
    case CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_LOG_ENTRY = 'LightsOn.Library.config.numberOfValidDaysInLogEntry';
}
