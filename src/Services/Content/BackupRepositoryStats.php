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

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Stats\BackupRepositoryStatsEntity;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;

class BackupRepositoryStats
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupRepositoryStats,
    )
    {}

    /**
     * @param array{totalSize: int|null, totalFileCount: int|null, snapshotsCount: int|null, fileSystemSize: int|null, checkStatus: string|null} $values
     */
    public function saveNewStats(string $backupRepositoryId, array $values): void
    {
        $data = [
            'id' => Uuid::randomHex(),
            'backupRepositoryId' => $backupRepositoryId,
            'totalSize' => $values['totalSize'],
            'totalFileCount' => $values['totalFileCount'],
            'snapshotsCount' => $values['snapshotsCount'],
            'fileSystemSize' => $values['fileSystemSize'],
            'checkStatus' => $values['checkStatus'] !== null ? substr($values['checkStatus'], 0, 254) : null,
            'created_at' => new \DateTime()
        ];

        $this->logger->debug('saveNewStats '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $this->backupRepositoryStats->create([$data], Context::createDefaultContext());
    }

    public function getLatestStatsByRepositoryId(string $backupRepositoryId): ?BackupRepositoryStatsEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('backupRepositoryId', $backupRepositoryId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $results = $this->backupRepositoryStats->search($criteria, Context::createDefaultContext());
        if ($results->count() >= 1) {

            /** @var BackupRepositoryStatsEntity $result */
            $result = $results->last();
            return $result;
        }

        return null;
    }
}
