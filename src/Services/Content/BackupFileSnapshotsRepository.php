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
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiRestic\Library\Backup;
use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Exception\InvalidConfigurationException;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Entity\RepositoryInitInputs;

class BackupFileSnapshotsRepository
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupFileSnapshotsRepository
    )
    {}

    public function saveSnapshots(string $backupRepositoryId, array $snapshots): void
    {
        $data = array();
        foreach ($snapshots as $snapshot) {
            $data[] = [
                'id' => Uuid::randomHex(),
                'backupRepositoryId' => $backupRepositoryId,
                'snapshotId' => $snapshot['id'],
                'snapshotShortId' => $snapshot['short_id'],
                'paths' => implode(',', $snapshot['paths']),
                'size' => \ByteUnits\Binary::bytes($snapshot['summary']['total_bytes_processed'])->format(),
                'createdAt' => \DateTime::createFromFormat(
                    'Y-m-d\TH:i:s.uP',
                    substr_replace($snapshot['time'],
                        '',
                        26,
                        3
                    )
                )
            ];
        }

        if(!empty($data)) {
            $this->backupFileSnapshotsRepository->create($data, Context::createDefaultContext());
        }
    }
}

