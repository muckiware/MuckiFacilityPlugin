<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;

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
     * Creates the directory including missing parent directories when it does not exist yet.
     *
     * A failing mkdir must not stay silent, otherwise the error shows up much later as a failing
     * dump without any hint about the real cause.
     *
     * @param string $path
     * @return bool True when the directory exists afterwards
     */
    public function ensureDirectoryExists(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, PluginDefaults::BACKUP_FOLDER_PERMISSION, true) || is_dir($path);
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

    /**
     * Method to calculate the total size in byte of a directory by absolute path.
     *
     * @param string $directoryPath absolute path
     * @param bool $recursive whether to include subdirectories
     * @return int size in byte
     *
     * @throws FilesystemException
     */
    public function getDirectorySize(string $directoryPath, bool $recursive=true): int
    {
        $directorySize = 0;
        $directoryFolders = explode('/', $directoryPath);
        $targetFolder = array_pop($directoryFolders);
        $workingPath = implode('/', $directoryFolders);

        $adapter = new LocalFilesystemAdapter($workingPath);
        $filesystem = new Filesystem($adapter);

        if(!$filesystem->directoryExists($targetFolder)) {
            return $directorySize;
        }

        $allFilePaths = $filesystem->listContents($targetFolder, $recursive)
            ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
            ->map(fn (StorageAttributes $attributes) => $attributes->path())
            ->toArray();

        /** @var string $filePath listContents() was mapped to the plain path above */
        foreach ($allFilePaths as $filePath) {
            $directorySize += $filesystem->fileSize($filePath);
        }

        return $directorySize;
    }
}
