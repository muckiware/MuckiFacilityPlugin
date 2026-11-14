<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\SettingsInterface;

class BackupFileSnapshotsRepositoryTest extends TestCase
{
    private const REPOSITORY_ID = '0123456789abcdef0123456789abcdef';
    private const SNAPSHOT_ID = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private MockObject&EntityRepository $entityRepository;
    private MockObject&LoggerInterface $logger;
    private BackupFileSnapshotsRepository $repository;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $payload = [];

    protected function setUp(): void
    {
        $this->payload = [];
        $this->entityRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->repository = new BackupFileSnapshotsRepository(
            $this->logger,
            $this->createMock(Connection::class),
            $this->createMock(SettingsInterface::class),
            $this->entityRepository,
            new Helper()
        );
    }

    private function expectCreateCapturesPayload(): void
    {
        $this->entityRepository->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (array $data): \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent {
                $this->payload = $data;

                return new \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent(
                    \Shopware\Core\Framework\Context::createDefaultContext(),
                    new \Shopware\Core\Framework\Event\NestedEventCollection(),
                    []
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function validSnapshot(string $id = self::SNAPSHOT_ID): array
    {
        return [
            'id' => $id,
            'short_id' => substr($id, 0, 8),
            'time' => '2024-03-01T02:00:00.123456789Z',
            'hostname' => 'shop',
            'paths' => ['/var/www'],
            'summary' => ['total_bytes_processed' => 2048, 'total_files_processed' => 7],
        ];
    }

    public function testSnapshotWithoutSummaryIsImportedWithoutSize(): void
    {
        $snapshot = $this->validSnapshot();
        unset($snapshot['summary']);
        $this->expectCreateCapturesPayload();

        $count = $this->repository->createNewSnapshots(self::REPOSITORY_ID, [$snapshot]);

        self::assertSame(1, $count);
        self::assertNull($this->payload[0]['size']);
        self::assertNull($this->payload[0]['totalFilesProcessed']);
    }

    public function testSnapshotWithSummaryKeepsSizeAndFileCount(): void
    {
        $this->expectCreateCapturesPayload();

        $this->repository->createNewSnapshots(self::REPOSITORY_ID, [$this->validSnapshot()]);

        self::assertNotNull($this->payload[0]['size']);
        self::assertSame(7, $this->payload[0]['totalFilesProcessed']);
    }

    public function testMissingHostnamePathsAndShortIdDoNotAbort(): void
    {
        $this->expectCreateCapturesPayload();

        $count = $this->repository->createNewSnapshots(
            self::REPOSITORY_ID,
            [['id' => self::SNAPSHOT_ID, 'time' => '2024-03-01T02:00:00Z']]
        );

        self::assertSame(1, $count);
        self::assertSame('', $this->payload[0]['hostname']);
        self::assertSame('', $this->payload[0]['paths']);
        self::assertSame(substr(self::SNAPSHOT_ID, 0, 8), $this->payload[0]['snapshotShortId']);
    }

    public function testOverlongHostnameIsTruncatedToColumnLength(): void
    {
        $this->expectCreateCapturesPayload();

        $this->repository->createNewSnapshots(
            self::REPOSITORY_ID,
            [['id' => self::SNAPSHOT_ID, 'time' => '2024-03-01T02:00:00Z', 'hostname' => str_repeat('ä', 200)]]
        );

        self::assertSame(str_repeat('ä', 128), $this->payload[0]['hostname']);
    }

    public function testSnapshotWithoutIdIsSkippedAndLogged(): void
    {
        $this->expectCreateCapturesPayload();
        $this->logger->expects(self::once())->method('warning');

        $count = $this->repository->createNewSnapshots(self::REPOSITORY_ID, [
            $this->validSnapshot(),
            ['time' => '2024-03-01T02:00:00Z'],
            $this->validSnapshot(str_repeat('b', 64)),
        ]);

        self::assertSame(2, $count);
        self::assertCount(2, $this->payload);
    }

    public function testLongPathListIsKeptCompletely(): void
    {
        $paths = [];
        for ($i = 0; $i < 20; $i++) {
            $paths[] = '/srv/data/' . str_pad((string) $i, 50, 'x');
        }
        $snapshot = $this->validSnapshot();
        $snapshot['paths'] = $paths;
        $this->expectCreateCapturesPayload();

        $this->repository->createNewSnapshots(self::REPOSITORY_ID, [$snapshot]);

        self::assertSame(implode(',', $paths), $this->payload[0]['paths']);
    }

    public function testEmptyListWritesNothing(): void
    {
        $this->entityRepository->expects(self::never())->method('create');

        self::assertSame(0, $this->repository->createNewSnapshots(self::REPOSITORY_ID, []));
    }
}
