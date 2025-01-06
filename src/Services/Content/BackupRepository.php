<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

use MuckiRestic\Library\Backup;
use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Exception\InvalidConfigurationException;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Entity\RepositoryInitInputs;

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
    public function initRepository(RepositoryInitInputs $backupRepositoryInput): ResultEntity
    {
        $backupClient = Backup::create();
        $ownResticPath = $this->pluginSettings->getOwnResticBinaryPath();
        if($ownResticPath) {
            $backupClient->setBinaryPath($ownResticPath);
        }
        $backupClient->setRepositoryPassword($backupRepositoryInput->getRepositoryPassword());
        $backupClient->setRepositoryPath($backupRepositoryInput->getRepositoryPath());
        return $backupClient->createRepository();
    }

    public function getBackupRepositoryById(string $backupRepositoryId): ?BackupRepositoryEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $backupRepositoryId));
        $criteria->addAssociation('backupRepositoryChecks');
        $criteria->setLimit(1);

        $backupRepository = $this->backupRepository->search($criteria, Context::createDefaultContext());
        if ($backupRepository->count() === 1) {
            return $backupRepository->first();
        }

        return null;
    }
}

