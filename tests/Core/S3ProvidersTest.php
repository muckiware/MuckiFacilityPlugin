<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\S3Providers;

class S3ProvidersTest extends TestCase
{
    public function testPathStyle(): void
    {
        self::assertFalse(S3Providers::AWS->usesPathStyle());
        self::assertTrue(S3Providers::MINIO->usesPathStyle());
        self::assertTrue(S3Providers::CEPH->usesPathStyle());
        self::assertTrue(S3Providers::OTHER->usesPathStyle());
    }

    public function testRequiresRegion(): void
    {
        self::assertTrue(S3Providers::AWS->requiresRegion());
        self::assertFalse(S3Providers::MINIO->requiresRegion());
    }

    public function testRequiresEndpoint(): void
    {
        self::assertFalse(S3Providers::AWS->requiresEndpoint());
        self::assertTrue(S3Providers::MINIO->requiresEndpoint());
        self::assertTrue(S3Providers::CEPH->requiresEndpoint());
        self::assertTrue(S3Providers::OTHER->requiresEndpoint());
    }

    public function testValues(): void
    {
        self::assertSame(S3Providers::MINIO, S3Providers::from('minio'));
        self::assertNull(S3Providers::tryFrom('gcs'));
    }
}
