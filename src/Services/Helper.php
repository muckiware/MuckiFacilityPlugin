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

use Psr\Log\LoggerInterface;

use MuckiRestic\ResultParser\CheckResultParser;
use MuckiRestic\Entity\Result\ResultEntity;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

/**
 *
 */
class Helper
{
    /**
     * @param array<mixed>|string $data
     * @return string
     */
    public function getHashData(array|string $data): string
    {
        if(is_array($data)) {
            return md5(serialize($data));
        }
        return md5($data);
    }

    /**
     * @param string $email
     * @return bool
     */
    public function isValidEmail(string $email): bool
    {
        // assume it's valid if we can't validate it
        if (!function_exists('filter_var')) {
            return true;
        }

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * @param string $path
     * @return void
     */
    public function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, PluginDefaults::BACKUP_FOLDER_PERMISSION, true);
        }
    }

    /**
     * @param string $dateTimeFormat
     * @return string
     */
    public function getCurrentDateTimeStr(string $dateTimeFormat): string
    {
        $date = new \DateTime();
        return $date->format($dateTimeFormat);
    }

    /**
     * @param string $dirPath
     * @return void
     */
    public function deleteDirectory(string $dirPath): void
    {
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $filePath = $dirPath . '/' . $file;
                    if (is_dir($filePath)) {
                        $this->deleteDirectory($filePath);
                    } else {
                        unlink($filePath);
                    }
                }
            }
            rmdir($dirPath);
        }
    }

    /**
     * @param string $checkResults
     * @return array<mixed>
     */
    public function getCheckResults(string $checkResults): array
    {
        return CheckResultParser::textParserResult($checkResults)->getProcessed();
    }

    public function createDateTimeFromString(string $dateString): ?\DateTime
    {
        $dateString = preg_replace('/(\.\d+)(\+|\-)/', '.000$2', $dateString);
        $dateTime = \DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $dateString);

        if (!$dateTime) {
            $dateTime = \DateTime::createFromFormat('Y-m-d\TH:i:sP', $dateString);
        }

        return $dateTime ?: null;
    }

    public function isValidShortId(string $input): bool
    {
        return preg_match('/^[a-zA-Z0-9]{8}$/', $input) === 1;
    }
}
