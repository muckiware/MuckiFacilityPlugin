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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksEntity;

class BackupRepositoryChecks
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupRepositoryChecks,
    )
    {}

    public function saveNewCheck(string $backupRepositoryId, string $checkStatus): void
    {
        $data = [
            'id' => Uuid::randomHex(),
            'backupRepositoryId' => $backupRepositoryId,
            'checkStatus' => substr($checkStatus, 0, 254),
            'created_at' => new \DateTime()
        ];

        $this->logger->debug('saveNewCheck '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $this->backupRepositoryChecks->create([$data], Context::createDefaultContext());
    }

    public function getLatestChecksByRepositoryId(string $backupRepositoryId): ?BackupRepositoryChecksEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('backupRepositoryId', $backupRepositoryId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $backupRepositoryResults = $this->backupRepositoryChecks->search($criteria, Context::createDefaultContext());
        if ($backupRepositoryResults->count() >= 1) {

            /** @var BackupRepositoryChecksEntity $result */
            $result = $backupRepositoryResults->last();
            return $result;
        }

        return null;
    }
}

