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
namespace MuckiFacilityPlugin\Services;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

class Helper
{
    public function getHashData(array|string $data): string
    {
        if(is_array($data)) {
            return md5(serialize($data));
        }
        return md5($data);
    }

    public function isValidEmail(string $email): bool
    {
        // assume it's valid if we can't validate it
        if (!function_exists('filter_var')) {
            return true;
        }

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public function checkBackupTypByInput(string $backupTypeInput): bool
    {
        $backupTypes = BackupTypes::cases();
        foreach ($backupTypes as $backupType) {
            if ($backupType->value === $backupTypeInput) {
                return true;
            }
        }

        return false;
    }

    public function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, PluginDefaults::BACKUP_FOLDER_PERMISSION, true);
        }
    }

    public function getCurrentDateTimeStr(string $dateTimeFormat): string
    {
        $date = new \DateTime();
        return $date->format($dateTimeFormat);
    }
}

