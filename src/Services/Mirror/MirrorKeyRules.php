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
 * Key rules of the restic layout: which keys are managed by the mirror and in which order they are uploaded.
 */
class MirrorKeyRules
{
    private const RANKS = ['data/' => 0, 'index/' => 1, 'keys/' => 2, 'snapshots/' => 3];
    private const CONFIG_RANK = 4;

    public static function normalizePrefix(?string $prefix): string
    {
        $segments = array_values(array_filter(explode('/', (string)$prefix), static fn(string $s): bool => $s !== ''));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid path segment in prefix.');
            }
        }

        return $segments === [] ? '' : implode('/', $segments) . '/';
    }

    public static function isManaged(string $relativeKey): bool
    {
        return self::rankOrNull($relativeKey) !== null;
    }

    public static function uploadRank(string $relativeKey): int
    {
        $rank = self::rankOrNull($relativeKey);
        if ($rank === null) {
            throw new \InvalidArgumentException('Key is not managed: ' . $relativeKey);
        }

        return $rank;
    }

    private static function rankOrNull(string $relativeKey): ?int
    {
        foreach (explode('/', $relativeKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        if ($relativeKey === 'config') {
            return self::CONFIG_RANK;
        }
        foreach (self::RANKS as $dir => $rank) {
            if (str_starts_with($relativeKey, $dir)) {
                return $rank;
            }
        }

        return null;
    }
}
