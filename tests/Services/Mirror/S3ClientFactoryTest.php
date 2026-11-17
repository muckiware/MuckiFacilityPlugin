<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Mirror;

use Aws\CommandInterface;
use Aws\Result;
use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Services\Mirror\S3ClientFactory;

class S3ClientFactoryTest extends TestCase
{
    public function testMinioUsesPathStyleAndDefaultRegion(): void
    {
        $client = (new S3ClientFactory())->create($this->connection('minio', 'http://minio.local:9000', null), 'secret');

        self::assertTrue($client->getConfig('use_path_style_endpoint'));
        self::assertSame('us-east-1', $client->getRegion());
        self::assertSame('http://minio.local:9000', (string) $client->getEndpoint());
    }

    public function testAwsUsesVirtualHostStyleAndRegion(): void
    {
        $client = (new S3ClientFactory())->create($this->connection('aws', null, 'eu-central-1'), 'secret');

        self::assertFalse($client->getConfig('use_path_style_endpoint'));
        self::assertSame('eu-central-1', $client->getRegion());
    }

    public function testCredentialsAreTaken(): void
    {
        $client = (new S3ClientFactory())->create($this->connection('aws', null, 'eu-central-1'), 'the-secret');
        $credentials = $client->getCredentials()->wait();

        self::assertSame('AKIA', $credentials->getAccessKeyId());
        self::assertSame('the-secret', $credentials->getSecretKey());
    }

    public function testHttpOptionsAreMergedIntoClientConfig(): void
    {
        $http = null;
        $handler = static function (CommandInterface $command) use (&$http): Result {
            $http = $command['@http'];

            return new Result([]);
        };
        $client = (new S3ClientFactory())->create(
            $this->connection('minio', 'http://minio.local:9000', null),
            'secret',
            $handler,
            ['connect_timeout' => 10, 'timeout' => 0, 'curl' => [\CURLOPT_LOW_SPEED_TIME => 300]]
        );

        $client->headBucket(['Bucket' => 'bucket']);

        self::assertSame([\CURLOPT_LOW_SPEED_TIME => 300], $http['curl']);
        self::assertSame(10, $http['connect_timeout']);
        self::assertSame(0, $http['timeout']);
    }

    public function testWithoutHttpOptionsNoTimeoutsAreSet(): void
    {
        $http = null;
        $handler = static function (CommandInterface $command) use (&$http): Result {
            $http = $command['@http'] ?? [];

            return new Result([]);
        };
        $client = (new S3ClientFactory())->create($this->connection('minio', 'http://minio.local:9000', null), 'secret', $handler);

        $client->headBucket(['Bucket' => 'bucket']);

        self::assertIsArray($http);
        self::assertArrayNotHasKey('connect_timeout', $http);
    }

    private function connection(string $provider, ?string $endpoint, ?string $region): S3ConnectionEntity
    {
        $entity = new S3ConnectionEntity();
        $entity->setProvider($provider);
        $entity->setEndpoint($endpoint);
        $entity->setRegion($region);
        $entity->setAccessKeyId('AKIA');

        return $entity;
    }
}
