<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;

class MediaRepository
{
    public function __construct(
        protected LoggerInterface $logger,
        protected EntityRepository $mediaRepository,
    )
    {}

    public function createCriteria(Filter $folderFilter = null, int $batchSize = null): Criteria
    {
        $criteria = new Criteria();
        $criteria->setOffset(0);

        if($batchSize) {
            $criteria->setLimit($batchSize);
        }
        $criteria->addFilter(new EqualsFilter('media.mediaFolder.configuration.createThumbnails', true));
        $criteria->addAssociation('thumbnails');
        $criteria->addAssociation('mediaFolder.configuration.mediaThumbnailSizes');

        if ($folderFilter) {
            $criteria->addFilter($folderFilter);
        }

        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('media.mimeType', 'image/jpeg'),
                new EqualsFilter('media.mimeType', 'image/png'),
                new EqualsFilter('media.mimeType', 'image/tiff')
            ])
        );

        return $criteria;
    }

    public function getMediaRepositoryById(string $mediaRepositoryId): ?MediaEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $mediaRepositoryId));
        $criteria->setLimit(1);

        $mediaRepositoryResults = $this->mediaRepository->search($criteria, Context::createDefaultContext());
        if ($mediaRepositoryResults->count() === 1) {

            /** @var MediaEntity $mediaRepository */
            $mediaRepository = $mediaRepositoryResults->first();
            return $mediaRepository;
        }

        return null;
    }

    public function getMediaRepository(Context $context): RepositoryIterator
    {
        /** @var RepositoryIterator<MediaCollection> $mediaIterator */
        return new RepositoryIterator($this->mediaRepository, $context, $this->createCriteria());
    }
}

