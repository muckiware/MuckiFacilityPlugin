<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Content;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Event\NestedEventCollection;

use MuckiFacilityPlugin\Entity\MirrorResult;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryUploads;

class BackupRepositoryUploadsTest extends TestCase
{
    private const REPOSITORY_ID = '0123456789abcdef0123456789abcdef';

    public function testSaveWritesAllResultFields(): void
    {
        $written = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('create')->willReturnCallback(
            function (array $data) use (&$written): EntityWrittenContainerEvent {
                $written = $data;

                return $this->writtenEvent();
            }
        );
        $repository->method('searchIds')->willReturnCallback(
            static fn (Criteria $c, Context $ctx): IdSearchResult => new IdSearchResult(0, [], $c, $ctx)
        );
        $repository->expects(self::never())->method('delete');

        (new BackupRepositoryUploads($repository))->save(self::REPOSITORY_ID, $this->mirrorResult());

        self::assertCount(1, $written);
        $row = $written[0];
        self::assertSame(self::REPOSITORY_ID, $row['backupRepositoryId']);
        self::assertSame('failed', $row['status']);
        self::assertSame('backup', $row['triggerSource']);
        self::assertSame('bucket', $row['bucket']);
        self::assertSame('repo/', $row['prefix']);
        self::assertSame('minio', $row['s3ConnectionName']);
        self::assertSame(2, $row['filesUploaded']);
        self::assertSame(1, $row['filesDeleted']);
        self::assertSame(3, $row['filesUnchanged']);
        self::assertSame(42, $row['bytesUploaded']);
        self::assertSame('boom', $row['message']);
        self::assertArrayHasKey('id', $row);
    }

    public function testKeepsOnlyLatestHundredEntries(): void
    {
        $searched = null;
        $deleted = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('create')->willReturn($this->writtenEvent());
        $repository->method('searchIds')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use (&$searched): IdSearchResult {
                $searched = $criteria;

                return new IdSearchResult(2, [
                    'old1' => ['primaryKey' => 'old1', 'data' => []],
                    'old2' => ['primaryKey' => 'old2', 'data' => []],
                ], $criteria, $context);
            }
        );
        $repository->expects(self::once())->method('delete')->willReturnCallback(
            function (array $ids) use (&$deleted): EntityWrittenContainerEvent {
                $deleted = $ids;

                return $this->writtenEvent();
            }
        );

        (new BackupRepositoryUploads($repository))->save(self::REPOSITORY_ID, $this->mirrorResult());

        self::assertInstanceOf(Criteria::class, $searched);
        self::assertSame(100, $searched->getOffset());
        $filters = $searched->getFilters();
        self::assertCount(1, $filters);
        self::assertInstanceOf(EqualsFilter::class, $filters[0]);
        self::assertSame('backupRepositoryId', $filters[0]->getField());
        self::assertSame(self::REPOSITORY_ID, $filters[0]->getValue());
        $sorting = $searched->getSorting()[0];
        self::assertInstanceOf(FieldSorting::class, $sorting);
        self::assertSame('createdAt', $sorting->getField());
        self::assertSame(FieldSorting::DESCENDING, $sorting->getDirection());
        self::assertSame([['id' => 'old1'], ['id' => 'old2']], $deleted);
    }

    private function mirrorResult(): MirrorResult
    {
        return new MirrorResult(
            status: MirrorResult::STATUS_FAILED,
            triggerSource: 'backup',
            bucket: 'bucket',
            prefix: 'repo/',
            s3ConnectionName: 'minio',
            startedAt: new \DateTimeImmutable('2026-11-16 10:00:00'),
            finishedAt: new \DateTimeImmutable('2026-11-16 10:05:00'),
            filesUploaded: 2,
            filesDeleted: 1,
            filesUnchanged: 3,
            bytesUploaded: 42,
            message: 'boom'
        );
    }

    private function writtenEvent(): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent(Context::createDefaultContext(), new NestedEventCollection(), []);
    }
}
