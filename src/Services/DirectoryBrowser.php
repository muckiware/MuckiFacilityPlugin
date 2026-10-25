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

use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;

/**
 * Read-only server folder browser. Only lists directories, never creates or changes anything.
 */
class DirectoryBrowser
{
    public const MAX_ENTRIES = 1000;
    protected const MAX_PATH_LENGTH = 4096;

    public function __construct(
        protected SettingsInterface $settings,
        protected KernelInterface $kernel
    ) {}

    /**
     * @return array{
     *     path: string,
     *     parent: ?string,
     *     directories: list<array{name: string, path: string, readable: bool, hidden: bool, link: bool}>,
     *     truncated: bool
     * }
     * @throws InvalidBrowsePathException
     */
    public function listDirectories(?string $path): array
    {
        $path = $this->nearestExistingDirectory($this->normalize($this->validate($path)));
        if (!is_readable($path)) {
            throw new InvalidBrowsePathException('Directory is not readable');
        }

        $names = $this->collectDirectoryNames($path);
        usort($names, 'strnatcasecmp');
        $truncated = count($names) > self::MAX_ENTRIES;

        $directories = [];
        foreach (array_slice($names, 0, self::MAX_ENTRIES) as $name) {
            $entryPath = ($path === '/' ? '' : $path) . '/' . $name;
            $directories[] = [
                'name' => $name,
                'path' => $entryPath,
                'readable' => is_readable($entryPath),
                'hidden' => str_starts_with($name, '.'),
                'link' => is_link($entryPath),
            ];
        }

        return [
            'path' => $path,
            'parent' => $path === '/' ? null : $this->parentOf($path),
            'directories' => $directories,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param list<string> $extensions
     * @return array{
     *     path: string,
     *     parent: ?string,
     *     directories: list<array{name: string, path: string, readable: bool, hidden: bool, link: bool}>,
     *     files: list<array{name: string, path: string, readable: bool, hidden: bool, link: bool}>,
     *     truncated: bool
     * }
     */
    public function listFiles(?string $path, array $extensions): array
    {
        $listing = $this->listDirectories($path);
        $extensions = array_values(array_filter(array_map(
            static fn (mixed $extension): string => strtolower(ltrim((string) $extension, '.')),
            $extensions
        ), static fn (string $extension): bool => preg_match('/^[a-z0-9]+$/', $extension) === 1));

        $names = [];
        foreach (scandir($listing['path']) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !mb_check_encoding((string) $name, 'UTF-8')) {
                continue;
            }
            $entryPath = ($listing['path'] === '/' ? '' : $listing['path']) . '/' . $name;
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if (is_file($entryPath) && in_array($extension, $extensions, true)) {
                $names[] = (string) $name;
            }
        }
        usort($names, 'strnatcasecmp');
        $listing['truncated'] = $listing['truncated'] || count($names) > self::MAX_ENTRIES;
        $listing['files'] = [];
        foreach (array_slice($names, 0, self::MAX_ENTRIES) as $name) {
            $entryPath = ($listing['path'] === '/' ? '' : $listing['path']) . '/' . $name;
            $listing['files'][] = [
                'name' => $name,
                'path' => $entryPath,
                'readable' => is_readable($entryPath),
                'hidden' => str_starts_with($name, '.'),
                'link' => is_link($entryPath),
            ];
        }

        return $listing;
    }

    /**
     * @return array{rejection: ?string, writable: bool, resolvedPath: ?string}
     */
    public function checkDumpPath(?string $dumpPath, ?string $repositoryPath, ?string $restorePath): array
    {
        if ($dumpPath === null || trim($dumpPath) === '') {
            return ['rejection' => null, 'writable' => true, 'resolvedPath' => null];
        }
        $this->validateCharacters($dumpPath);

        $rejection = $this->settings->getOwnDumpPathRejection($dumpPath, $repositoryPath, $restorePath);
        $resolved = $this->settings->resolveDumpPathValue($dumpPath);

        return [
            'rejection' => $rejection?->value,
            'writable' => is_writable($this->nearestExistingDirectory($resolved)),
            'resolvedPath' => $resolved,
        ];
    }

    /**
     * @throws InvalidBrowsePathException
     */
    protected function validate(?string $path): string
    {
        $path = $path ?? '';
        $this->validateCharacters($path);
        if (array_intersect(explode('/', $path), ['.', '..']) !== []) {
            throw new InvalidBrowsePathException('Path contains relative segments');
        }

        return $path;
    }

    /**
     * @throws InvalidBrowsePathException
     */
    protected function validateCharacters(string $path): void
    {
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new InvalidBrowsePathException('Path is too long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidBrowsePathException('Path contains control characters');
        }
    }

    /**
     * Purely lexical: no realpath(), symlinks stay as the user typed them.
     */
    protected function normalize(string $path): string
    {
        if (trim($path) === '') {
            $path = $this->kernel->getProjectDir();
        } elseif (!str_starts_with($path, '/')) {
            $path = $this->kernel->getProjectDir() . '/' . $path;
        }

        $path = rtrim((string) preg_replace('#/+#', '/', $path), '/');

        return $path === '' ? '/' : $path;
    }

    protected function nearestExistingDirectory(string $path): string
    {
        $path = $this->normalize($path);
        while ($path !== '/' && !is_dir($path)) {
            $path = $this->parentOf($path);
        }

        return $path;
    }

    protected function parentOf(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' ? '/' : $parent;
    }

    /**
     * @return list<string>
     * @throws InvalidBrowsePathException
     */
    protected function collectDirectoryNames(string $path): array
    {
        $entries = scandir($path);
        if ($entries === false) {
            throw new InvalidBrowsePathException('Directory is not readable');
        }

        $names = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!mb_check_encoding((string) $name, 'UTF-8')) {
                continue; // JsonResponse cannot encode malformed UTF-8
            }
            if (is_dir(($path === '/' ? '' : $path) . '/' . $name)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
