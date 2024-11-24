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
namespace MuckiFacilityPlugin\Backup;

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Backup\Database\CompleteFileRunner;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;
use MuckiFacilityPlugin\Services\SettingsInterface;

class BackupRunnerFactory
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $settings
    ) {}

    /**
     * @throws InvalidBackupTypeException
     */
    public function createBackupRunner(string $backupType): BackupInterface
    {
        switch ($backupType) {

            case BackupTypes::DATABASE_ALL->value:
                $runner = new CompleteFileRunner($this->logger, $this->settings);
                break;

            default:

                $this->logger->error('Invalid backup type:'.$backupType);
                throw new InvalidBackupTypeException();
        }

        return $runner;
    }
}
