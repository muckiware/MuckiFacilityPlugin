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
            'checkStatus' => $checkStatus,
            'created_at' => new \DateTime()
        ];

        $this->logger->debug('saveNewCheck '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $this->backupRepositoryChecks->create([$data], Context::createDefaultContext());
    }
}

