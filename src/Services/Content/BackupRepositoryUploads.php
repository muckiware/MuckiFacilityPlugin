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
namespace MuckiFacilityPlugin\Services\Content;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\BackupRepository\Uploads\BackupRepositoryUploadsEntity;
use MuckiFacilityPlugin\Entity\MirrorResult;

/**
 * History of S3 mirror runs per repository. Keeps only the newest entries.
 */
class BackupRepositoryUploads
{
    public const MAX_ENTRIES = 100;

    /**
     * Upper bound of entries removed per save; the table grows by one entry per save.
     */
    protected const PRUNE_BATCH = 1000;

    /**
     * @param EntityRepository<\MuckiFacilityPlugin\Core\Content\BackupRepository\Uploads\BackupRepositoryUploadsCollection> $uploadsRepository
     */
    public function __construct(
        protected EntityRepository $uploadsRepository
    )
    {}

    public function save(string $backupRepositoryId, MirrorResult $result): void
    {
        $context = Context::createDefaultContext();
        $this->uploadsRepository->create([[
            'id' => Uuid::randomHex(),
            'backupRepositoryId' => $backupRepositoryId,
            'status' => $result->getStatus(),
            'triggerSource' => $result->getTriggerSource(),
            'bucket' => $result->getBucket(),
            'prefix' => $result->getPrefix(),
            's3ConnectionName' => $result->getS3ConnectionName(),
            'startedAt' => $result->getStartedAt(),
            'finishedAt' => $result->getFinishedAt(),
            'filesUploaded' => $result->getFilesUploaded(),
            'filesDeleted' => $result->getFilesDeleted(),
            'filesUnchanged' => $result->getFilesUnchanged(),
            'bytesUploaded' => $result->getBytesUploaded(),
            'message' => $result->getMessage(),
        ]], $context);

        $this->prune($backupRepositoryId, $context);
    }

    public function getLatest(string $backupRepositoryId): ?BackupRepositoryUploadsEntity
    {
        return $this->findLatest($this->latestCriteria($backupRepositoryId));
    }

    public function getLatestSucceeded(string $backupRepositoryId): ?BackupRepositoryUploadsEntity
    {
        $criteria = $this->latestCriteria($backupRepositoryId);
        $criteria->addFilter(new EqualsFilter('status', MirrorResult::STATUS_SUCCEEDED));

        return $this->findLatest($criteria);
    }

    protected function prune(string $backupRepositoryId, Context $context): void
    {
        $criteria = $this->latestCriteria($backupRepositoryId);
        $criteria->setOffset(self::MAX_ENTRIES);
        $criteria->setLimit(self::PRUNE_BATCH);

        $ids = $this->uploadsRepository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }

        $this->uploadsRepository->delete(
            array_values(array_map(static fn ($id): array => ['id' => $id], $ids)),
            $context
        );
    }

    protected function latestCriteria(string $backupRepositoryId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('backupRepositoryId', $backupRepositoryId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        return $criteria;
    }

    protected function findLatest(Criteria $criteria): ?BackupRepositoryUploadsEntity
    {
        $criteria->setLimit(1);
        $entity = $this->uploadsRepository->search($criteria, Context::createDefaultContext())->first();

        return $entity instanceof BackupRepositoryUploadsEntity ? $entity : null;
    }
}
