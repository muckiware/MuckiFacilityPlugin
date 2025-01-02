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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

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

            $this->logger->debug('saveSnapshots '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->backupFileSnapshotsRepository->create($data, Context::createDefaultContext());
        }
    }
}

