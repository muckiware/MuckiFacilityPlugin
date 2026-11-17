<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Mirror;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Exception\InvalidMirrorTargetException;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;
use MuckiFacilityPlugin\Services\Mirror\MirrorTargetValidator;

final class MirrorTargetValidatorTest extends TestCase
{
    private const CONN_A = '11111111111111111111111111111111';
    private const CONN_B = '22222222222222222222222222222222';
    private const CURRENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var array<string, S3ConnectionEntity> */
    private array $connections = [];

    /** @var list<BackupRepositoryEntity> */
    private array $others = [];

    protected function setUp(): void
    {
        $this->connections = [
            self::CONN_A => $this->connection(self::CONN_A, 'minio', 'https://S3.example.com/', null),
            self::CONN_B => $this->connection(self::CONN_B, 'minio', 'https://s3.example.com', null),
        ];
        $this->others = [];
    }

    private function connection(string $id, string $provider, ?string $endpoint, ?string $region): S3ConnectionEntity
    {
        $c = new S3ConnectionEntity();
        $c->setId($id);
        $c->setName('conn-' . $id[0]);
        $c->setProvider($provider);
        $c->setEndpoint($endpoint);
        $c->setRegion($region);

        return $c;
    }

    private function other(string $connectionId, string $bucket, string $prefix): BackupRepositoryEntity
    {
        $r = new BackupRepositoryEntity();
        $r->setId(self::OTHER);
        $r->setName('Other repo');
        $r->setMirrorS3ConnectionId($connectionId);
        $r->setMirrorS3Bucket($bucket);
        $r->setMirrorS3Prefix($prefix);

        return $r;
    }

    private function validator(): MirrorTargetValidator
    {
        $s3 = $this->createMock(S3ConnectionRepository::class);
        $s3->method('getById')->willReturnCallback(fn (string $id): ?S3ConnectionEntity => $this->connections[$id] ?? null);
        $repo = $this->createMock(BackupRepositoryService::class);
        $repo->method('findMirrorConfigured')->willReturnCallback(
            function (string $excludeId): array {
                self::assertSame(self::CURRENT, $excludeId);
                return $this->others;
            }
        );

        return new MirrorTargetValidator($s3, $repo);
    }

    public function testValidInputIsNormalized(): void
    {
        $result = $this->validator()->assertValid([
            's3ConnectionId' => self::CONN_A, 'bucket' => ' my-bucket ', 'prefix' => '/shop//db',
        ]);

        self::assertSame(
            ['mirrorS3ConnectionId' => self::CONN_A, 'mirrorS3Bucket' => 'my-bucket', 'mirrorS3Prefix' => 'shop/db/'],
            $result
        );
    }

    public function testNullConnectionClearsAllFieldsAndIgnoresBucket(): void
    {
        foreach ([null, ''] as $empty) {
            self::assertSame(
                ['mirrorS3ConnectionId' => null, 'mirrorS3Bucket' => null, 'mirrorS3Prefix' => null],
                $this->validator()->assertValid(['s3ConnectionId' => $empty, 'bucket' => 'x', 'prefix' => '..'])
            );
        }
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(array $input): void
    {
        $this->expectException(InvalidMirrorTargetException::class);
        $this->validator()->assertValid($input);
    }

    public static function invalidInputs(): array
    {
        $c = self::CONN_A;

        return [
            'dot dot prefix' => [['s3ConnectionId' => $c, 'bucket' => 'abc', 'prefix' => 'a/../b']],
            'dot prefix' => [['s3ConnectionId' => $c, 'bucket' => 'abc', 'prefix' => './a']],
            'upper bucket' => [['s3ConnectionId' => $c, 'bucket' => 'My_Bucket']],
            'missing bucket' => [['s3ConnectionId' => $c]],
            'empty bucket' => [['s3ConnectionId' => $c, 'bucket' => '  ']],
            'short bucket' => [['s3ConnectionId' => $c, 'bucket' => 'ab']],
            'long bucket' => [['s3ConnectionId' => $c, 'bucket' => str_repeat('a', 64)]],
            'ip bucket' => [['s3ConnectionId' => $c, 'bucket' => '192.168.0.1']],
            'dot dot bucket' => [['s3ConnectionId' => $c, 'bucket' => 'a..b']],
            'bucket edge dash' => [['s3ConnectionId' => $c, 'bucket' => '-abc']],
            'unknown connection' => [['s3ConnectionId' => 'cccccccccccccccccccccccccccccccc', 'bucket' => 'abc']],
            'malformed id' => [['s3ConnectionId' => 'nope', 'bucket' => 'abc']],
            'array id' => [['s3ConnectionId' => [self::CONN_A], 'bucket' => 'abc']],
            'array bucket' => [['s3ConnectionId' => $c, 'bucket' => ['abc']]],
            'array prefix' => [['s3ConnectionId' => $c, 'bucket' => 'abc', 'prefix' => ['x']]],
            'int bucket' => [['s3ConnectionId' => $c, 'bucket' => 123456]],
            'newline in prefix' => [['s3ConnectionId' => $c, 'bucket' => 'abc', 'prefix' => "a\nb"]],
            'cr in bucket' => [['s3ConnectionId' => $c, 'bucket' => "abc\r"]],
            'nul in prefix' => [['s3ConnectionId' => $c, 'bucket' => 'abc', 'prefix' => "a\0b"]],
            'newline in id' => [['s3ConnectionId' => self::CONN_A . "\n", 'bucket' => 'abc']],
        ];
    }

    #[DataProvider('bucketNames')]
    public function testIsValidBucketName(string $name, bool $valid): void
    {
        self::assertSame($valid, MirrorTargetValidator::isValidBucketName($name));
    }

    public static function bucketNames(): array
    {
        return [
            ['my-bucket', true], ['a.b-c.1', true], ['abc', true], [str_repeat('a', 63), true],
            ['ab', false], [str_repeat('a', 64), false], ['My_Bucket', false], ['a..b', false],
            ['1.2.3.4', false], ['-ab', false], ['ab-', false], ['.ab', false], ["ab\n", false], ['', false],
        ];
    }

    #[DataProvider('overlaps')]
    public function testOverlap(string $existingPrefix, string $newPrefix, bool $overlaps): void
    {
        $this->others = [$this->other(self::CONN_A, 'bucket', $existingPrefix)];

        $result = $this->validator()->findOverlap(self::CURRENT, self::CONN_A, 'bucket', $newPrefix);

        self::assertSame($overlaps ? 'Other repo' : null, $result);
    }

    public static function overlaps(): array
    {
        return [
            ['shop/', 'shop/', true],
            ['', 'shop/', true],
            ['shop/', '', true],
            ['shop/', 'shop/db/', true],
            ['shop/db/', 'shop/', true],
            ['shop/', 'shopware/', false],
            ['shopware/', 'shop/', false],
        ];
    }

    public function testDifferentBucketDoesNotOverlap(): void
    {
        $this->others = [$this->other(self::CONN_A, 'bucket', '')];

        self::assertNull($this->validator()->findOverlap(self::CURRENT, self::CONN_A, 'other-bucket', ''));
    }

    public function testSameEndpointViaDifferentConnectionsOverlaps(): void
    {
        // CONN_A: https://S3.example.com/  CONN_B: https://s3.example.com  -> same normalized endpoint
        $this->others = [$this->other(self::CONN_A, 'bucket', 'x/')];

        self::assertSame('Other repo', $this->validator()->findOverlap(self::CURRENT, self::CONN_B, 'bucket', 'x/'));
    }

    public function testSchemeIsIgnoredForEndpointComparison(): void
    {
        $this->connections[self::CONN_A] = $this->connection(self::CONN_A, 'minio', 'http://MinIO.local:9000/s3/', null);
        $this->connections[self::CONN_B] = $this->connection(self::CONN_B, 'minio', 'https://minio.local:9000/s3', null);
        $this->others = [$this->other(self::CONN_A, 'bucket', '')];

        self::assertSame('Other repo', $this->validator()->findOverlap(self::CURRENT, self::CONN_B, 'bucket', 'x/'));
    }

    public function testDifferentPortDoesNotOverlap(): void
    {
        $this->connections[self::CONN_A] = $this->connection(self::CONN_A, 'minio', 'http://minio.local:9000', null);
        $this->connections[self::CONN_B] = $this->connection(self::CONN_B, 'minio', 'http://minio.local:9001', null);
        $this->others = [$this->other(self::CONN_A, 'bucket', '')];

        self::assertNull($this->validator()->findOverlap(self::CURRENT, self::CONN_B, 'bucket', ''));
    }

    public function testDifferentEndpointDoesNotOverlap(): void
    {
        $this->connections[self::CONN_B] = $this->connection(self::CONN_B, 'minio', 'https://other.example.com', null);
        $this->others = [$this->other(self::CONN_A, 'bucket', '')];

        self::assertNull($this->validator()->findOverlap(self::CURRENT, self::CONN_B, 'bucket', ''));
    }

    public function testAwsTargetsCollideAcrossRegionsAndEndpoints(): void
    {
        $this->connections[self::CONN_A] = $this->connection(self::CONN_A, 'aws', null, 'eu-central-1');
        $this->connections[self::CONN_B] = $this->connection(self::CONN_B, 'aws', 'https://s3.us-east-1.amazonaws.com', 'us-east-1');
        $this->others = [$this->other(self::CONN_A, 'bucket', '')];

        self::assertSame('Other repo', $this->validator()->findOverlap(self::CURRENT, self::CONN_B, 'bucket', 'a/'));
        self::assertNull($this->validator()->findOverlap(self::CURRENT, self::CONN_B, 'other-bucket', 'a/'));
    }

    public function testOtherRepositoryWithUnloadableConnectionIsACollision(): void
    {
        $this->others = [$this->other('dddddddddddddddddddddddddddddddd', 'bucket', '')];

        self::assertSame('Other repo', $this->validator()->findOverlap(self::CURRENT, self::CONN_A, 'bucket', ''));
    }

    public function testMissingOwnConnectionThrows(): void
    {
        $this->expectException(InvalidMirrorTargetException::class);
        $this->validator()->findOverlap(self::CURRENT, 'cccccccccccccccccccccccccccccccc', 'bucket', '');
    }
}
