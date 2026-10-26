<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 */
namespace MuckiFacilityPlugin\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

class BatchFileUploader
{
    private const TARGET_DIRECTORY = '/files/private/mucki-facility/batch';

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function upload(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The batch file upload failed');
        }

        $fileName = $this->getSafeFileName($file->getClientOriginalName());
        if (strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)) !== 'sh') {
            throw new \InvalidArgumentException('Only .sh files can be uploaded');
        }

        $directory = $this->kernel->getProjectDir() . self::TARGET_DIRECTORY;
        $file->move($directory, $fileName);

        return $directory . '/' . $fileName;
    }

    private function getSafeFileName(string $originalName): string
    {
        $fileName = basename(str_replace('\\', '/', trim($originalName)));
        if ($fileName === '' || str_contains($fileName, "\0")) {
            throw new \InvalidArgumentException('The batch file name is invalid');
        }

        return $fileName;
    }
}
