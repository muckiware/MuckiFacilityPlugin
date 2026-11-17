<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksEntity;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Entity\MirrorResult;
use MuckiFacilityPlugin\Events\AbstractRepositoryUploadEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadFailedEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadSucceededEvent;
use MuckiFacilityPlugin\Services\AdminNotification;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryUploads;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;
use MuckiFacilityPlugin\Services\Mirror\LocalRepositoryScanner;
use MuckiFacilityPlugin\Services\Mirror\MirrorPlanner;
use MuckiFacilityPlugin\Services\Mirror\MirrorTargetValidator;
use MuckiFacilityPlugin\Services\Mirror\S3ClientFactory;
use MuckiFacilityPlugin\Services\RepositoryMirror;

class RepositoryMirrorTest extends TestCase
{
    private const REPOSITORY_ID = '0123456789abcdef0123456789abcdef';
    private const CONNECTION_ID = 'fedcba9876543210fedcba9876543210';
    private const CHECK_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SECRET = 'TOPSECRET-wJalrXUtnFEMI';
    private const HEALTHY = 'no errors were found';

    private const LOCAL_FILES = [
        'config' => 'CFG1',
        'keys/k1' => 'key',
        'data/ab/pack1' => 'pack-data',
        'index/i1' => 'idx',
        'snapshots/s1' => 'snap',
    ];

    private string $path;
    private MockHandler $mock;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $operations = [];
    /** @var list<array<string, mixed>> */
    private array $httpOptions = [];
    /** @var list<RequestInterface> */
    private array $requests = [];
    /** @var list<MirrorResult> */
    private array $saved = [];
    /** @var list<AbstractRepositoryUploadEvent> */
    private array $events = [];
    /** @var list<array<string, mixed>> */
    private array $notifications = [];
    /** @var list<string> */
    private array $logs = [];
    /** @var (callable(CommandInterface): mixed)|null */
    private $interceptor = null;

    private ?BackupRepositoryEntity $repository;
    private ?BackupRepositoryChecksEntity $check;
    private S3ConnectionEntity $connection;
    private LockFactory $lockFactory;
    private MockObject&S3ConnectionRepository $connections;
    /** @var list<BackupRepositoryEntity> */
    private array $others = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/muwa-mirror-' . bin2hex(random_bytes(6));
        foreach (self::LOCAL_FILES as $key => $content) {
            $this->writeLocal($key, $content);
        }
        mkdir($this->path . '/locks');

        $this->mock = new MockHandler();
        $this->lockFactory = new LockFactory(new InMemoryStore());

        $this->repository = new BackupRepositoryEntity();
        $this->repository->setId(self::REPOSITORY_ID);
        $this->repository->setName('Main repository');
        $this->repository->setRepositoryPath($this->path);
        $this->repository->setMirrorS3ConnectionId(self::CONNECTION_ID);
        $this->repository->setMirrorS3Bucket('bucket');
        $this->repository->setMirrorS3Prefix('/repo');

        $this->check = $this->check(self::REPOSITORY_ID, self::HEALTHY);

        $this->connection = new S3ConnectionEntity();
        $this->connection->setId(self::CONNECTION_ID);
        $this->connection->setName('MinIO');
        $this->connection->setProvider('minio');
        $this->connection->setEndpoint('http://minio.local:9000');
        $this->connection->setAccessKeyId('AKIA');
        $this->connection->setSecretSource('encrypted');

        $this->connections = $this->createMock(S3ConnectionRepository::class);
        $this->connections->method('getById')->willReturnCallback(
            fn (string $id): ?S3ConnectionEntity => $id === self::CONNECTION_ID ? $this->connection : null
        );
        $this->connections->method('resolveSecret')->willReturn(self::SECRET);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->path);
    }

    public function testHappyPathUploadsThenDeletesAndRecordsSucceeded(): void
    {
        $this->queueListing([
            'repo/config' => 4,
            'repo/keys/k1' => 3,
            'repo/data/ab/old' => 10,
            'repo/snapshots/s0' => 5,
            'repo/foreign/file' => 1,
            'repo/locks/l1' => 1,
            'repoX/data/zz' => 1,
        ]);
        $this->mock->append(new Result(['Body' => 'CFG1']));
        $this->mock->append(new Result([]), new Result([]), new Result([]));
        $this->mock->append(new Result(['Deleted' => [['Key' => 'repo/snapshots/s0'], ['Key' => 'repo/data/ab/old']]]));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertNotNull($result);
        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result->getStatus(), (string) $result->getMessage());
        self::assertSame(
            ['ListObjectsV2', 'GetObject', 'PutObject', 'PutObject', 'PutObject', 'DeleteObjects'],
            $this->operationNames()
        );
        self::assertSame('repo/', $this->operations[0][1]['Prefix']);
        self::assertSame('repo/config', $this->operations[1][1]['Key']);
        self::assertSame(
            ['repo/data/ab/pack1', 'repo/index/i1', 'repo/snapshots/s1'],
            array_map(static fn (array $op): string => $op[1]['Key'], array_slice($this->operations, 2, 3))
        );
        self::assertSame(
            [['Key' => 'repo/snapshots/s0'], ['Key' => 'repo/data/ab/old']],
            $this->operations[5][1]['Delete']['Objects']
        );
        self::assertSame(3, $result->getFilesUploaded());
        self::assertSame(2, $result->getFilesDeleted());
        self::assertSame(2, $result->getFilesUnchanged());
        self::assertSame(16, $result->getBytesUploaded());
        self::assertSame('backup', $result->getTriggerSource());
        self::assertSame('bucket', $result->getBucket());
        self::assertSame('repo/', $result->getPrefix());
        self::assertSame('MinIO', $result->getS3ConnectionName());
        self::assertSame([$result], $this->saved);
        self::assertCount(1, $this->events);
        self::assertInstanceOf(RepositoryUploadSucceededEvent::class, $this->events[0]);
        self::assertSame('Main repository', $this->events[0]->getValues()['backupRepositoryName']);
        self::assertSame([], $this->notifications);
        self::assertCount(1, $this->httpOptions);
    }

    public function testOverlappingTargetFailsWithoutAnyS3Call(): void
    {
        $other = new BackupRepositoryEntity();
        $other->setId('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $other->setName('Other repo');
        $other->setMirrorS3ConnectionId(self::CONNECTION_ID);
        $other->setMirrorS3Bucket('bucket');
        $other->setMirrorS3Prefix('');
        $this->others = [$other];

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsString('Other repo', (string) $result->getMessage());
        self::assertStringContainsString('nothing was changed remotely', (string) $result->getMessage());
        self::assertSame([], $this->operations);
        self::assertSame([], $this->httpOptions);
    }

    public function testInvalidBucketFailsWithoutAnyS3Call(): void
    {
        $this->repository->setMirrorS3Bucket('My_Bucket');

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsString('nothing was changed remotely', (string) $result->getMessage());
        self::assertSame([], $this->operations);
        self::assertSame([], $this->httpOptions);
    }

    public function testHttpOptionsContainStallProtection(): void
    {
        $this->queueListing([]);
        $this->mock->append(new Result([]), new Result([]), new Result([]), new Result([]), new Result([]));

        $this->mirror()->mirror(self::REPOSITORY_ID, 'manual', null);

        self::assertCount(1, $this->httpOptions);
        $options = $this->httpOptions[0];
        self::assertSame(10, $options['connect_timeout']);
        self::assertSame(0, $options['timeout']);
        self::assertSame(300, $options['read_timeout']);
        self::assertSame(1024, $options['curl'][\CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(300, $options['curl'][\CURLOPT_LOW_SPEED_TIME]);
        // reaches the Guzzle request options of every command
        self::assertSame($options['curl'], $this->operations[0][1]['@http']['curl']);
    }

    public function testMirrorNeverThrowsWhenBuildingTheResultFails(): void
    {
        $this->connection = new S3ConnectionEntity(); // getName() on an uninitialized property throws \Error
        $this->connection->setId(self::CONNECTION_ID);

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertNull($result);
        self::assertNotSame([], array_filter($this->logs, static fn (string $l): bool => str_contains($l, 'S3 mirror')));
        $lock = $this->lockFactory->createLock('mucki-facility.mirror.' . self::REPOSITORY_ID, 3600);
        self::assertTrue($lock->acquire(false), 'mirror lock must be released');
    }

    public function testLockIsRefreshedByTimeEvenWithFewFiles(): void
    {
        $this->queueListing([]);
        $this->mock->append(new Result([]), new Result([]), new Result([]), new Result([]), new Result([]));
        $lock = $this->expectLock(self::exactly(5));

        $result = $this->clockedMirror(700.0)->mirror(self::REPOSITORY_ID, 'manual', null);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
        self::assertSame(5, $result?->getFilesUploaded());
        unset($lock);
    }

    public function testLockIsNotRefreshedWithoutElapsedTimeOrFiftyFiles(): void
    {
        $this->queueListing([]);
        $this->mock->append(new Result([]), new Result([]), new Result([]), new Result([]), new Result([]));
        $this->expectLock(self::never());

        $result = $this->clockedMirror(0.0)->mirror(self::REPOSITORY_ID, 'manual', null);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
    }

    public function testMissingLocalDataRefusesDeletingRemoteData(): void
    {
        foreach (['data/ab/pack1', 'index/i1', 'snapshots/s1'] as $key) {
            unlink($this->path . '/' . $key);
        }
        $this->queueListing(['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/data/ab/pack1' => 9]);
        $this->mock->append(new Result(['Body' => 'CFG1']));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'manual', null, true);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsString('data/', (string) $result?->getMessage());
        self::assertSame(['ListObjectsV2', 'GetObject'], $this->operationNames());
        self::assertSame(0, $result?->getFilesDeleted());
    }

    public function testFirstMirrorUploadsConfigLastWithoutGetObject(): void
    {
        $this->queueListing([]);
        $this->mock->append(new Result([]), new Result([]), new Result([]), new Result([]), new Result([]));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'manual', null);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus());
        self::assertSame(
            ['repo/data/ab/pack1', 'repo/index/i1', 'repo/keys/k1', 'repo/snapshots/s1', 'repo/config'],
            array_map(static fn (array $op): string => $op[1]['Key'], array_slice($this->operations, 1))
        );
        self::assertNotContains('GetObject', $this->operationNames());
        self::assertNotContains('DeleteObjects', $this->operationNames());
    }

    public function testPaginatedRemoteListingIsMerged(): void
    {
        $this->mock->append(new Result([
            'IsTruncated' => true,
            'NextContinuationToken' => 'next',
            'Contents' => [['Key' => 'repo/keys/k1', 'Size' => '3'], ['Key' => 'repo/data/ab/pack1', 'Size' => '9']],
        ]));
        $this->queueListing(['repo/index/i1' => 3, 'repo/snapshots/s1' => 4, 'repo/config' => 4]);
        $this->mock->append(new Result(['Body' => 'CFG1']));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
        self::assertSame(['ListObjectsV2', 'ListObjectsV2', 'GetObject'], $this->operationNames());
        self::assertSame('next', $this->operations[1][1]['ContinuationToken']);
        self::assertSame(5, $result->getFilesUnchanged());
        self::assertSame(0, $result->getFilesUploaded());
    }

    public function testRedCheckSkipsWithoutAnyS3Call(): void
    {
        $this->check = $this->check(self::REPOSITORY_ID, 'Fatal: repository contains errors');
        $this->connections->expects(self::never())->method('resolveSecret');

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SKIPPED, $result?->getStatus());
        self::assertSame([], $this->operations);
        self::assertSame(0, $this->mock->count());
        self::assertSame([$result], $this->saved);
        self::assertCount(1, $this->events);
        self::assertInstanceOf(RepositoryUploadFailedEvent::class, $this->events[0]);
        self::assertSame('skipped', $this->events[0]->getValues()['status']);
        $this->assertNotification('muwa-backup-repository.notification.mirrorSkipped');
    }

    public function testMissingCheckSkips(): void
    {
        $this->check = null;

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', null);

        self::assertSame(MirrorResult::STATUS_SKIPPED, $result?->getStatus());
        self::assertSame([], $this->operations);
    }

    public function testBackupTriggerWithoutCheckIdSkipsDespiteHealthyLatestCheck(): void
    {
        // the latest stored check is green, but this backup run produced no check result
        $this->check = $this->check(self::REPOSITORY_ID, self::HEALTHY);
        $this->connections->expects(self::never())->method('resolveSecret');

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', null);

        self::assertSame(MirrorResult::STATUS_SKIPPED, $result?->getStatus());
        self::assertStringContainsString('No check result from this backup run', (string) $result?->getMessage());
        self::assertSame([], $this->operations);
        self::assertSame(0, $this->mock->count());
    }

    public function testCliTriggerWithoutCheckIdUsesLatestHealthyCheck(): void
    {
        $this->queueListing(array_combine(
            array_map(static fn (string $k): string => 'repo/' . $k, array_keys(self::LOCAL_FILES)),
            array_map('strlen', array_values(self::LOCAL_FILES))
        ));
        $this->mock->append(new Result(['Body' => 'CFG1']));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'cli', null);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
    }

    public function testFirstMirrorIntoForeignTargetNeverDeletes(): void
    {
        // no remote config at start, but foreign layout keys below the prefix
        $this->queueListing(['repo/data/zz/foreign' => 7, 'repo/snapshots/foreign' => 3]);
        $this->mock->append(new Result([]), new Result([]), new Result([]), new Result([]), new Result([]));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus());
        self::assertNotContains('DeleteObjects', $this->operationNames());
        self::assertSame(0, $result?->getFilesDeleted());
        self::assertSame(5, $result?->getFilesUploaded());
        self::assertStringContainsString('deferred', (string) $result?->getMessage());
    }

    public function testCheckOfAnotherRepositorySkips(): void
    {
        $this->check = $this->check('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', self::HEALTHY);

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SKIPPED, $result?->getStatus());
        self::assertSame([], $this->operations);
    }

    public function testIgnoreCheckMirrorsDespiteRedCheck(): void
    {
        $this->check = $this->check(self::REPOSITORY_ID, 'Fatal: errors');
        $this->queueListing(array_combine(
            array_map(static fn (string $k): string => 'repo/' . $k, array_keys(self::LOCAL_FILES)),
            array_map('strlen', array_values(self::LOCAL_FILES))
        ));
        $this->mock->append(new Result(['Body' => 'CFG1']));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'manual', null, true);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
    }

    public function testMissingLocalRepositoryNeverDeletes(): void
    {
        $this->repository?->setRepositoryPath($this->path . '/does-not-exist');

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertSame([], $this->operations);
        $this->assertNotification('muwa-backup-repository.notification.mirrorFailed');
        self::assertInstanceOf(RepositoryUploadFailedEvent::class, $this->events[0]);
    }

    public function testRepositoryWithoutKeysNeverDeletes(): void
    {
        unlink($this->path . '/keys/k1');

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertSame([], $this->operations);
    }

    public function testActiveLockAbortsWithoutRemoteChange(): void
    {
        file_put_contents($this->path . '/locks/abc', 'lock');

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsStringIgnoringCase('locked', (string) $result?->getMessage());
        self::assertSame([], $this->operations);
    }

    public function testLockAppearingBeforeDeletePhaseSkipsDeletes(): void
    {
        $this->queueListing(['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/data/ab/old' => 10]);
        $this->mock->append(new Result(['Body' => 'CFG1']), new Result([]), new Result([]));
        $this->mock->append(function (): Result {
            file_put_contents($this->path . '/locks/abc', 'lock');

            return new Result([]);
        });

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsStringIgnoringCase('locked', (string) $result?->getMessage());
        self::assertNotContains('DeleteObjects', $this->operationNames());
        self::assertSame(3, $result?->getFilesUploaded());
        self::assertSame(0, $result?->getFilesDeleted());
    }

    public function testDifferentRemoteConfigAborts(): void
    {
        $this->queueListing(['repo/config' => 4, 'repo/data/ab/old' => 10]);
        $this->mock->append(new Result(['Body' => 'CFG2']));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsStringIgnoringCase('different repository', (string) $result?->getMessage());
        self::assertSame(['ListObjectsV2', 'GetObject'], $this->operationNames());
    }

    public function testUploadErrorSkipsDeletesAndRecordsProgress(): void
    {
        $this->queueListing(['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/data/ab/old' => 10]);
        $this->mock->append(new Result(['Body' => 'CFG1']), new Result([]), new Result([]));
        $putCount = 0;
        $this->interceptor = static function (CommandInterface $command) use (&$putCount) {
            if ($command->getName() !== 'PutObject' || ++$putCount < 3) {
                return null;
            }

            return Create::rejectionFor(new S3Exception('Internal', $command, [
                'code' => 'InternalError',
                'message' => 'We encountered an internal error',
                'response' => new Response(500),
            ]));
        };

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsString('InternalError', (string) $result?->getMessage());
        self::assertSame(2, $result?->getFilesUploaded());
        self::assertSame(12, $result?->getBytesUploaded());
        self::assertNotContains('DeleteObjects', $this->operationNames());
    }

    public function testDeleteErrorsAreReportedAsFailed(): void
    {
        $this->queueListing(['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/data/ab/pack1' => 9,
            'repo/index/i1' => 3, 'repo/snapshots/s1' => 4, 'repo/data/ab/old' => 10, 'repo/data/ab/old2' => 10]);
        $this->mock->append(new Result(['Body' => 'CFG1']));
        $this->mock->append(new Result([
            'Deleted' => [['Key' => 'repo/data/ab/old']],
            'Errors' => [['Key' => 'repo/data/ab/old2', 'Code' => 'AccessDenied', 'Message' => 'Access Denied']],
        ]));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsString('AccessDenied', (string) $result?->getMessage());
        self::assertSame(1, $result?->getFilesDeleted());
    }

    public function testDeletesAreBatchedByThousand(): void
    {
        $listing = ['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/data/ab/pack1' => 9,
            'repo/index/i1' => 3, 'repo/snapshots/s1' => 4];
        for ($i = 0; $i < 1001; $i++) {
            $listing[sprintf('repo/data/cd/stale%04d', $i)] = 1;
        }
        $this->queueListing($listing);
        $this->mock->append(new Result(['Body' => 'CFG1']), new Result([]), new Result([]));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
        self::assertSame(['ListObjectsV2', 'GetObject', 'DeleteObjects', 'DeleteObjects'], $this->operationNames());
        self::assertCount(1000, $this->operations[2][1]['Delete']['Objects']);
        self::assertCount(1, $this->operations[3][1]['Delete']['Objects']);
        self::assertSame(1001, $result?->getFilesDeleted());
    }

    public function testLargeFilesUseMultipartUploadWithoutAcl(): void
    {
        $this->queueListing(['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/index/i1' => 3, 'repo/snapshots/s1' => 4]);
        $this->mock->append(new Result(['Body' => 'CFG1']));
        $this->mock->append(
            new Result(['UploadId' => 'u1']),
            new Result(['ETag' => '"e1"']),
            new Result([])
        );
        $mirror = new class (...$this->dependencies()) extends RepositoryMirror {
            protected function multipartThreshold(): int
            {
                return 5;
            }
        };

        $result = $mirror->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
        self::assertSame(
            ['ListObjectsV2', 'GetObject', 'CreateMultipartUpload', 'UploadPart', 'CompleteMultipartUpload'],
            $this->operationNames()
        );
        self::assertArrayNotHasKey('ACL', array_filter($this->operations[2][1], static fn ($v): bool => $v !== null));
        self::assertFalse($this->requests[2]->hasHeader('x-amz-acl'));
    }

    public function testSecretNeverReachesHistoryOrEvent(): void
    {
        $this->mock->append(static fn () => new \RuntimeException('signing failed for secret ' . self::SECRET));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_FAILED, $result?->getStatus());
        self::assertStringContainsString('signing failed', (string) $result?->getMessage());
        self::assertStringNotContainsString(self::SECRET, (string) $result?->getMessage());
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($this->events[0]->getValues()));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($this->notifications));
        self::assertStringNotContainsString(self::SECRET, implode("\n", $this->logs));
    }

    public function testRefreshesLockDuringLongUploads(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->writeLocal(sprintf('data/ef/pack%02d', $i), 'x');
        }
        $this->queueListing(['repo/config' => 4, 'repo/keys/k1' => 3, 'repo/index/i1' => 3, 'repo/snapshots/s1' => 4]);
        $this->mock->append(new Result(['Body' => 'CFG1']));
        for ($i = 0; $i < 51; $i++) {
            $this->mock->append(new Result([]));
        }
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::atLeastOnce())->method('refresh');
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())->method('createLock')
            ->with('mucki-facility.mirror.' . self::REPOSITORY_ID, 3600.0)
            ->willReturn($lock);
        $this->lockFactory = $lockFactory;

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SUCCEEDED, $result?->getStatus(), (string) $result?->getMessage());
        self::assertSame(51, $result?->getFilesUploaded());
    }

    public function testBusyMirrorLockReturnsNull(): void
    {
        $held = $this->lockFactory->createLock('mucki-facility.mirror.' . self::REPOSITORY_ID, 3600);
        self::assertTrue($held->acquire(false));

        $result = $this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertNull($result);
        self::assertSame([], $this->operations);
        self::assertSame([], $this->saved);
        self::assertSame([], $this->events);
        self::assertSame([], $this->notifications);
    }

    public function testNoConnectionReturnsNull(): void
    {
        $this->repository?->setMirrorS3ConnectionId(null);
        $this->connections->expects(self::never())->method('getById');

        self::assertNull($this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID));
        self::assertSame([], $this->saved);
        self::assertSame([], $this->events);
        self::assertSame([], $this->notifications);
    }

    public function testUnknownRepositoryReturnsNull(): void
    {
        $this->repository = null;

        self::assertNull($this->mirror()->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID));
        self::assertSame([], $this->saved);
        self::assertSame([], $this->events);
    }

    public function testFailingHistoryWriteDoesNotThrow(): void
    {
        $this->check = $this->check(self::REPOSITORY_ID, 'Fatal');
        $uploads = $this->createMock(BackupRepositoryUploads::class);
        $uploads->method('save')->willThrowException(new \RuntimeException('db down'));
        $dependencies = $this->dependencies();
        $dependencies[3] = $uploads;

        $result = (new RepositoryMirror(...$dependencies))->mirror(self::REPOSITORY_ID, 'backup', self::CHECK_ID);

        self::assertSame(MirrorResult::STATUS_SKIPPED, $result?->getStatus());
        self::assertCount(1, $this->events);
    }

    /**
     * Mirror whose clock advances by $step seconds on every reading.
     */
    private function clockedMirror(float $step): RepositoryMirror
    {
        $mirror = new class (...$this->dependencies()) extends RepositoryMirror {
            public float $step = 0.0;
            private float $time = 0.0;

            protected function now(): float
            {
                $current = $this->time;
                $this->time += $this->step;

                return $current;
            }
        };
        $mirror->step = $step;

        return $mirror;
    }

    private function expectLock(\PHPUnit\Framework\MockObject\Rule\InvocationOrder $refreshes): SharedLockInterface
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects($refreshes)->method('refresh');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);
        $this->lockFactory = $lockFactory;

        return $lock;
    }

    private function mirror(): RepositoryMirror
    {
        return new RepositoryMirror(...$this->dependencies());
    }

    /**
     * @return list<mixed>
     */
    private function dependencies(): array
    {
        $logger = new class ($this->logs) extends AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = $message . ' ' . json_encode($context);
            }
        };

        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('findMirrorConfigured')->willReturnCallback(fn (): array => $this->others);
        $backupRepository->method('getBackupRepositoryById')->willReturnCallback(
            fn (string $id): ?BackupRepositoryEntity => $id === self::REPOSITORY_ID ? $this->repository : null
        );

        $checks = $this->createMock(BackupRepositoryChecks::class);
        $checks->method('getById')->willReturnCallback(
            fn (string $id): ?BackupRepositoryChecksEntity => $id === self::CHECK_ID ? $this->check : null
        );
        $checks->method('getLatestChecksByRepositoryId')->willReturnCallback(fn () => $this->check);

        $uploads = $this->createMock(BackupRepositoryUploads::class);
        $uploads->method('save')->willReturnCallback(function (string $id, MirrorResult $result): void {
            $this->saved[] = $result;
        });

        $factory = $this->createMock(S3ClientFactory::class);
        $factory->method('create')->willReturnCallback(
            function (S3ConnectionEntity $connection, string $secret, ?callable $handler = null, array $httpOptions = []) {
                $this->httpOptions[] = $httpOptions;

                return (new S3ClientFactory())->create($connection, $secret, $this->handler(), $httpOptions);
            }
        );

        $dispatcher = new EventDispatcher();
        foreach ([RepositoryUploadSucceededEvent::EVENT_NAME, RepositoryUploadFailedEvent::EVENT_NAME] as $name) {
            $dispatcher->addListener($name, function (AbstractRepositoryUploadEvent $event): void {
                $this->events[] = $event;
            });
        }

        $notification = $this->createMock(AdminNotification::class);
        $notification->method('createNotification')->willReturnCallback(function (array $data): void {
            $this->notifications[] = $data;
        });

        return [
            $logger,
            $backupRepository,
            $checks,
            $uploads,
            $this->connections,
            $factory,
            new LocalRepositoryScanner(),
            new MirrorPlanner(),
            $this->lockFactory,
            $dispatcher,
            $notification,
            new MirrorTargetValidator($this->connections, $backupRepository),
        ];
    }

    private function handler(): callable
    {
        return function (CommandInterface $command, RequestInterface $request) {
            $this->operations[] = [$command->getName(), $command->toArray()];
            $this->requests[] = $request;
            if ($this->interceptor !== null) {
                $intercepted = ($this->interceptor)($command);
                if ($intercepted !== null) {
                    return $intercepted;
                }
            }

            return ($this->mock)($command, $request);
        };
    }

    /**
     * @param array<string, int> $objects
     */
    private function queueListing(array $objects): void
    {
        $contents = [];
        foreach ($objects as $key => $size) {
            $contents[] = ['Key' => (string) $key, 'Size' => (string) $size];
        }
        $this->mock->append(new Result(['IsTruncated' => false, 'Contents' => $contents]));
    }

    /**
     * @return list<string>
     */
    private function operationNames(): array
    {
        return array_map(static fn (array $op): string => $op[0], $this->operations);
    }

    private function assertNotification(string $snippet): void
    {
        self::assertCount(1, $this->notifications);
        self::assertSame('error', $this->notifications[0]['status']);
        $message = json_decode((string) $this->notifications[0]['message'], true);
        self::assertSame($snippet, $message['snippet']);
        self::assertSame('Main repository', $message['parameters']['name']);
        self::assertArrayHasKey('message', $message['parameters']);
    }

    private function check(string $repositoryId, string $status): BackupRepositoryChecksEntity
    {
        $check = new BackupRepositoryChecksEntity();
        $check->setId(self::CHECK_ID);
        $check->setBackupRepositoryId($repositoryId);
        $check->setCheckStatus($status);

        return $check;
    }

    private function writeLocal(string $key, string $content): void
    {
        $file = $this->path . '/' . $key;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $full = $dir . '/' . $entry;
            is_dir($full) && !is_link($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($dir);
    }
}
