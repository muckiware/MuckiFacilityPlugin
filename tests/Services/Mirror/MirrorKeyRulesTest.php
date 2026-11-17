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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\Mirror\MirrorKeyRules;

class MirrorKeyRulesTest extends TestCase
{
    #[DataProvider('prefixes')]
    public function testPrefixNormalization(?string $in, string $out): void
    {
        self::assertSame($out, MirrorKeyRules::normalizePrefix($in));
    }

    public static function prefixes(): array
    {
        return [[null, ''], ['', ''], ['/', ''], ['backups', 'backups/'], ['/backups//shop/', 'backups/shop/']];
    }

    #[DataProvider('badPrefixes')]
    public function testDotSegmentsAreRejected(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MirrorKeyRules::normalizePrefix($prefix);
    }

    public static function badPrefixes(): array
    {
        return [['a/../b'], ['..'], ['./a'], ['a/.']];
    }

    #[DataProvider('managed')]
    public function testIsManaged(string $key, bool $expected): void
    {
        self::assertSame($expected, MirrorKeyRules::isManaged($key));
    }

    public static function managed(): array
    {
        return [
            ['config', true], ['keys/k', true], ['data/aa/1', true], ['index/i', true], ['snapshots/s', true],
            ['locks/l', false], ['README.txt', false], ['other/data/x', false], ['config2', false],
            ['data', false], ['configs/x', false], ['', false],
            ['data/../config', false], ['data//x', false], ['./data/x', false], ['data/./x', false],
            ['keys/..', false], ['data/x/', false], ['/data/x', false],
        ];
    }

    public function testUploadRank(): void
    {
        self::assertSame(0, MirrorKeyRules::uploadRank('data/a'));
        self::assertSame(1, MirrorKeyRules::uploadRank('index/a'));
        self::assertSame(2, MirrorKeyRules::uploadRank('keys/a'));
        self::assertSame(3, MirrorKeyRules::uploadRank('snapshots/a'));
        self::assertSame(4, MirrorKeyRules::uploadRank('config'));
    }
}
