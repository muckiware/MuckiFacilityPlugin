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
namespace MuckiFacilityPlugin\Services;

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\ConfigPath;
use MuckiFacilityPlugin\Backup\BackupFactory;
use MuckiFacilityPlugin\Core\BackupTypes;

class Backup
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupFactory $backupFactory
    )
    {}

    public function createBackup(string $backupType): void
    {
        try {
            $backupRunner = $this->backupFactory->createBackupClient($backupType);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
