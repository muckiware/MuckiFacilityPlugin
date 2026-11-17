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
namespace MuckiFacilityPlugin\Services\Mirror;

/**
 * Reads the local restic repository: sanity checks and a flat listing of regular files.
 */
class LocalRepositoryScanner
{
    public function isResticRepository(string $path): bool
    {
        if (!is_file($path . '/config') || is_link($path . '/config')) {
            return false;
        }

        return $this->hasFiles($path . '/keys');
    }

    public function hasActiveLocks(string $path): bool
    {
        $dir = $path . '/locks';
        if (!is_dir($dir)) {
            return false;
        }
        $entries = is_readable($dir) ? scandir($dir) : false;
        if ($entries === false) {
            return true; // unreadable: fail closed
        }

        return count(array_diff($entries, ['.', '..'])) > 0;
    }

    /**
     * @return array<string,int> relative key (always "/") => size; locks/ is left out; any other symlink throws
     */
    public function scan(string $path): array
    {
        $result = [];
        $this->walk(rtrim($path, '/'), '', $result);

        return $result;
    }

    /**
     * @param array<string,int> $result
     */
    private function walk(string $base, string $relative, array &$result): void
    {
        $entries = scandir($base . ($relative === '' ? '' : '/' . $relative));
        if ($entries === false) {
            throw new \RuntimeException('Cannot read directory: ' . $relative);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $rel = $relative === '' ? $entry : $relative . '/' . $entry;
            $full = $base . '/' . $rel;
            if ($rel === 'locks') {
                continue;
            }
            if (is_link($full)) {
                throw new \RuntimeException('Symlink inside repository is not supported: ' . $rel);
            }
            if (is_dir($full)) {
                $this->walk($base, $rel, $result);
            } elseif (is_file($full)) {
                $size = filesize($full);
                if ($size === false) {
                    throw new \RuntimeException('Cannot read file size: ' . $rel);
                }
                $result[$rel] = $size;
            }
        }
    }

    private function hasFiles(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir)) {
            return false;
        }
        foreach (is_readable($dir) ? (scandir($dir) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }
}
