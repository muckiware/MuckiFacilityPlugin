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

use MuckiFacilityPlugin\Entity\MirrorPlan;

/**
 * Decides what is uploaded and what is deleted remotely. Unmanaged keys are never touched,
 * config is never deleted.
 */
class MirrorPlanner
{
    /**
     * @param array<string,int> $local  relative key => size
     * @param array<string,int> $remote relative key (prefix already stripped) => size
     */
    public function plan(array $local, array $remote): MirrorPlan
    {
        $uploads = [];
        $unchanged = 0;
        foreach ($local as $key => $size) {
            $key = (string)$key;
            if (!MirrorKeyRules::isManaged($key)) {
                continue;
            }
            if (array_key_exists($key, $remote) && $remote[$key] === $size) {
                $unchanged++;
                continue;
            }
            $uploads[] = $key;
        }

        $deletes = [];
        foreach ($remote as $key => $size) {
            $key = (string)$key;
            if ($key === 'config' || !MirrorKeyRules::isManaged($key) || array_key_exists($key, $local)) {
                continue;
            }
            $deletes[] = $key;
        }

        if ($deletes !== [] && !$this->hasConfigAndKeys($local)) {
            throw new \RuntimeException('Refusing to plan remote deletions: local listing has no config or keys.');
        }

        return new MirrorPlan(
            $this->sortByRank($uploads, false),
            $this->sortByRank($deletes, true),
            $unchanged
        );
    }

    /**
     * @param array<string,int> $local
     */
    private function hasConfigAndKeys(array $local): bool
    {
        if (!array_key_exists('config', $local)) {
            return false;
        }
        foreach ($local as $key => $size) {
            if (str_starts_with((string)$key, 'keys/') && MirrorKeyRules::isManaged((string)$key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function sortByRank(array $keys, bool $descending): array
    {
        usort($keys, static function (string $a, string $b) use ($descending): int {
            $cmp = self::order($a, $descending) <=> self::order($b, $descending);

            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });

        return $keys;
    }

    /**
     * Upload order is the restic rank; delete order is snapshots, index, keys, data.
     */
    private static function order(string $key, bool $descending): int
    {
        $rank = MirrorKeyRules::uploadRank($key);
        if (!$descending) {
            return $rank;
        }

        return match ($rank) {
            3 => 0,
            1 => 1,
            2 => 2,
            default => 3,
        };
    }
}
