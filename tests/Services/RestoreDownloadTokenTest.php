<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use MuckiFacilityPlugin\Services\RestoreDownloadToken;

class RestoreDownloadTokenTest extends TestCase
{
    public function testIssuedTokenRedeemsToRepositoryAndPath(): void
    {
        $service = new RestoreDownloadToken(new ArrayAdapter());

        $token = $service->issue('id', 'a/b.backup.sql');

        static::assertSame(['backupRepositoryId' => 'id', 'path' => 'a/b.backup.sql'], $service->redeem($token));
    }

    public function testTokenIsSingleUse(): void
    {
        $service = new RestoreDownloadToken(new ArrayAdapter());
        $token = $service->issue('id', 'a/b.backup.sql');

        $service->redeem($token);

        static::assertNull($service->redeem($token));
    }

    public function testUnknownTokenReturnsNull(): void
    {
        static::assertNull((new RestoreDownloadToken(new ArrayAdapter()))->redeem('unknown'));
    }

    public function testTokenIsRandomAnd64HexChars(): void
    {
        $service = new RestoreDownloadToken(new ArrayAdapter());

        $first = $service->issue('id', 'a');
        $second = $service->issue('id', 'a');

        static::assertNotSame($first, $second);
        static::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }
}
