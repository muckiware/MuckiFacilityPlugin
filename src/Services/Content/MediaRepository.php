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

class MediaRepository
{
    public function __construct(
        protected LoggerInterface $logger,
        protected EntityRepository $mediaRepository,
    )
    {}

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
}

