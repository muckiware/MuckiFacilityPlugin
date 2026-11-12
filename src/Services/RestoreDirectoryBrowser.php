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

use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\Content\BackupRepository;

/**
 * Read-only browser for the restore path of one backup repository. This is the security boundary
 * for dump downloads: nothing outside the real restore path is ever listed or resolved, all paths
 * handed in and out are relative to the restore path.
 */
class RestoreDirectoryBrowser
{
    public const DUMP_FILE_SUFFIXES = ['.backup.sql.tar.gz', '.backup.sql.gz', '.backup.sql'];
    public const MAX_ENTRIES = 1000;
    protected const MAX_PATH_LENGTH = 4096;

    public function __construct(
        protected BackupRepository $backupRepository,
        protected SettingsInterface $settings
    ) {}

    /**
     * @return array{
     *     state: string,
     *     rejection: ?string,
     *     path: string,
     *     parent: ?string,
     *     directories: list<array{name: string, path: string}>,
     *     files: list<array{name: string, path: string, size: int, modifiedAt: string, downloadable: bool}>,
     *     truncated: bool
     * }
     * @throws InvalidBrowsePathException
     */
    public function listDirectory(string $backupRepositoryId, ?string $relativePath): array
    {
        $relativePath = $this->validateRelativePath($relativePath ?? '');
        $root = $this->resolveRoot($backupRepositoryId, $state, $rejection);
        if ($root === null) {
            return $this->emptyListing($state, $rejection);
        }

        $directory = $this->resolveInside($root, $relativePath);
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new InvalidBrowsePathException('Directory does not exist or is not readable');
        }

        return $this->buildListing($directory, $relativePath);
    }

    /**
     * @throws InvalidBrowsePathException
     */
    public function resolveDownloadFile(string $backupRepositoryId, string $relativePath): string
    {
        $relativePath = $this->validateRelativePath($relativePath);
        $root = $this->resolveRoot($backupRepositoryId, $state, $rejection);
        if ($root === null) {
            throw new InvalidBrowsePathException('Restore path is not available');
        }

        $file = $this->resolveInside($root, $relativePath);
        if ($relativePath === '' || !is_file($file) || !is_readable($file) || !$this->isDumpFile($file)) {
            throw new InvalidBrowsePathException('File is not a downloadable dump');
        }

        return $file;
    }

    /**
     * Returns the real restore path, or null and the resulting state when it cannot be browsed.
     *
     * @throws InvalidBrowsePathException
     */
    protected function resolveRoot(string $backupRepositoryId, ?string &$state, ?string &$rejection): ?string
    {
        $rejection = null;
        $repository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        if ($repository === null) {
            throw new InvalidBrowsePathException('Unknown backup repository');
        }

        $restorePath = trim($repository->getRestorePath());
        if ($restorePath === '') {
            $state = 'notConfigured';
            return null;
        }

        $violation = $this->settings->getRestorePathRejection(
            $restorePath,
            $repository->getRepositoryPath(),
            $repository->getDbDumpPath()
        );
        if ($violation !== null) {
            $state = 'notAllowed';
            $rejection = $violation->value;
            return null;
        }

        $resolved = $this->settings->resolveDumpPathValue($restorePath);
        $root = is_dir($resolved) ? realpath($resolved) : false;
        if ($root === false) {
            $state = 'empty';
            return null;
        }

        $state = 'ok';
        return $root;
    }

    /**
     * @throws InvalidBrowsePathException
     */
    protected function validateRelativePath(string $path): string
    {
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new InvalidBrowsePathException('Path is too long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidBrowsePathException('Path contains control characters');
        }
        if (str_starts_with($path, '/') || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            throw new InvalidBrowsePathException('Path must be relative without relative segments');
        }

        return trim($path, '/');
    }

    /**
     * @throws InvalidBrowsePathException
     */
    protected function resolveInside(string $root, string $relativePath): string
    {
        $real = $relativePath === '' ? $root : realpath($root . '/' . $relativePath);
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . '/'))) {
            throw new InvalidBrowsePathException('Path is outside of the restore path');
        }

        return $real;
    }

    protected function isDumpFile(string $path): bool
    {
        foreach (self::DUMP_FILE_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     state: string,
     *     rejection: ?string,
     *     path: string,
     *     parent: ?string,
     *     directories: list<array{name: string, path: string}>,
     *     files: list<array{name: string, path: string, size: int, modifiedAt: string, downloadable: bool}>,
     *     truncated: bool
     * }
     */
    protected function emptyListing(?string $state, ?string $rejection): array
    {
        return [
            'state' => (string) $state,
            'rejection' => $rejection,
            'path' => '',
            'parent' => null,
            'directories' => [],
            'files' => [],
            'truncated' => false,
        ];
    }

    /**
     * @return array{
     *     state: string,
     *     rejection: ?string,
     *     path: string,
     *     parent: ?string,
     *     directories: list<array{name: string, path: string}>,
     *     files: list<array{name: string, path: string, size: int, modifiedAt: string, downloadable: bool}>,
     *     truncated: bool
     * }
     * @throws InvalidBrowsePathException
     */
    protected function buildListing(string $directory, string $relativePath): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new InvalidBrowsePathException('Directory is not readable');
        }

        $dirNames = [];
        $fileNames = [];
        foreach ($entries as $name) {
            $name = (string) $name;
            $full = $directory . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($full) || !mb_check_encoding($name, 'UTF-8')) {
                continue;
            }
            if (is_dir($full)) {
                $dirNames[] = $name;
            } elseif (is_file($full)) {
                $fileNames[] = $name;
            }
        }
        usort($dirNames, 'strnatcasecmp');
        usort($fileNames, 'strnatcasecmp');

        $listing = $this->emptyListing('ok', null);
        $listing['path'] = $relativePath;
        $listing['parent'] = $relativePath === '' ? null : $this->parentOf($relativePath);
        $listing['truncated'] = count($dirNames) > self::MAX_ENTRIES || count($fileNames) > self::MAX_ENTRIES;
        foreach (array_slice($dirNames, 0, self::MAX_ENTRIES) as $name) {
            $listing['directories'][] = ['name' => $name, 'path' => $this->join($relativePath, $name)];
        }
        foreach (array_slice($fileNames, 0, self::MAX_ENTRIES) as $name) {
            $listing['files'][] = $this->describeFile($directory . '/' . $name, $name, $relativePath);
        }

        return $listing;
    }

    /**
     * @return array{name: string, path: string, size: int, modifiedAt: string, downloadable: bool}
     */
    protected function describeFile(string $full, string $name, string $relativePath): array
    {
        return [
            'name' => $name,
            'path' => $this->join($relativePath, $name),
            'size' => (int) filesize($full),
            'modifiedAt' => (new \DateTimeImmutable('@' . (int) filemtime($full)))->format(\DATE_ATOM),
            'downloadable' => $this->isDumpFile($name) && is_readable($full),
        ];
    }

    protected function parentOf(string $relativePath): string
    {
        $parent = dirname($relativePath);

        return $parent === '.' ? '' : $parent;
    }

    protected function join(string $relativePath, string $name): string
    {
        return $relativePath === '' ? $name : $relativePath . '/' . $name;
    }
}
