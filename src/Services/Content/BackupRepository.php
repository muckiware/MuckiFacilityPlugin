<?php

namespace MuckiFacilityPlugin\Services\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;

use MuckiRestic\Library\Backup;
use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Exception\InvalidConfigurationException;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;

class BackupRepository
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupRepository,
    )
    {}

    /**
     * @throws InvalidConfigurationException
     */
    public function initRepository(string $backupRepositoryId): ResultEntity
    {
        $backupRepository = $this->getBackupRepositoryById($backupRepositoryId);
        $backupClient = Backup::create();
        $ownResticPath = $this->pluginSettings->getOwnResticBinaryPath();
        if($ownResticPath) {
            $backupClient->setBinaryPath($ownResticPath);
        }
        $backupClient->setRepositoryPassword($backupRepository->getRepositoryPassword());
        $backupClient->setRepositoryPath($backupRepository->getRepositoryPath());
        return $backupClient->createRepository();
    }

    public function getBackupRepositoryById(string $backupRepositoryId): ?BackupRepositoryEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $backupRepositoryId));
        $criteria->setLimit(1);

        $backupRepository = $this->backupRepository->search($criteria, Context::createDefaultContext());
        if ($backupRepository->count() === 1) {
            return $backupRepository->first();
        }

        return null;
    }
}

