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
namespace MuckiFacilityPlugin\Tests\Services\Mirror;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\Mirror\MirrorPlanner;

class MirrorPlannerTest extends TestCase
{
    public function testUploadsMissingAndSizeChangedInResticOrder(): void
    {
        $plan = (new MirrorPlanner())->plan(
            ['config' => 10, 'keys/k' => 5, 'data/aa/1' => 100, 'index/i' => 7, 'snapshots/s' => 3],
            ['config' => 10, 'data/aa/1' => 99]
        );
        self::assertSame(['data/aa/1', 'index/i', 'keys/k', 'snapshots/s'], $plan->getUploads());
        self::assertSame(1, $plan->getUnchangedCount());
    }

    public function testDeletesOnlyManagedKeysInReverseOrderAndNeverConfig(): void
    {
        $plan = (new MirrorPlanner())->plan(
            ['config' => 1, 'keys/k' => 1],
            ['config' => 1, 'keys/k' => 1, 'data/x' => 1, 'index/y' => 1, 'snapshots/z' => 1]
        );
        self::assertSame(['snapshots/z', 'index/y', 'data/x'], $plan->getDeletes());
    }

    public function testConfigMissingLocallyIsNeverDeleted(): void
    {
        $plan = (new MirrorPlanner())->plan(['keys/k' => 1], ['config' => 1, 'keys/k' => 1]);
        self::assertSame([], $plan->getDeletes());
    }

    public function testForeignAndLockKeysAreNeverTouched(): void
    {
        $plan = (new MirrorPlanner())->plan(
            ['config' => 1],
            ['config' => 1, 'README.txt' => 1, 'locks/l' => 1, 'other/data/x' => 1]
        );
        self::assertSame([], $plan->getDeletes());
        self::assertSame([], $plan->getUploads());
        self::assertSame(1, $plan->getUnchangedCount());
    }

    public function testUnmanagedLocalKeysAreIgnored(): void
    {
        $plan = (new MirrorPlanner())->plan(['config' => 1, 'locks/l' => 1, 'x.txt' => 2], ['config' => 1]);
        self::assertSame([], $plan->getUploads());
        self::assertSame(1, $plan->getUnchangedCount());
    }

    public function testSortingWithinRankIsAlphabeticalAndDeletesGroupedByRank(): void
    {
        $plan = (new MirrorPlanner())->plan(
            ['config' => 1, 'keys/k0' => 1, 'data/b' => 1, 'data/a' => 1, 'index/z' => 1, 'index/y' => 1],
            ['data/d' => 1, 'data/c' => 1, 'keys/k2' => 1, 'keys/k1' => 1, 'index/q' => 1, 'snapshots/s' => 1]
        );
        self::assertSame(['data/a', 'data/b', 'index/y', 'index/z', 'keys/k0', 'config'], $plan->getUploads());
        self::assertSame(['snapshots/s', 'index/q', 'keys/k1', 'keys/k2', 'data/c', 'data/d'], $plan->getDeletes());
    }

    public function testDeletesWithoutLocalConfigAndKeysThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        (new MirrorPlanner())->plan([], ['data/x' => 1]);
    }

    public function testDeletesWithoutLocalKeysThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        (new MirrorPlanner())->plan(['config' => 1, 'data/y' => 1], ['data/x' => 1]);
    }

    public function testDeletesWithoutLocalConfigThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        (new MirrorPlanner())->plan(['keys/k' => 1], ['data/x' => 1]);
    }

    public function testDeletesWithConfigAndKeysStillWork(): void
    {
        $plan = (new MirrorPlanner())->plan(['config' => 1, 'keys/k' => 1], ['data/x' => 1]);
        self::assertSame(['data/x'], $plan->getDeletes());
    }

    public function testNoDeletesWithEmptyLocalDoesNotThrow(): void
    {
        $plan = (new MirrorPlanner())->plan([], ['README' => 1]);
        self::assertSame([], $plan->getDeletes());
    }
}
