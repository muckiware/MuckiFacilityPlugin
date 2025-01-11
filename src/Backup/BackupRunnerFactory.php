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
use MuckiFacilityPlugin\Backup\Database\CompleteFilesRunner;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
use MuckiFacilityPlugin\Backup\Files\FilesRunner;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class BackupRunnerFactory
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $settings,
        protected CoreDatabase $database,
        protected ServicesCliOutput $servicesCliOutput
    ) {}

    /**
     * @throws InvalidBackupTypeException
     */
    public function createBackupRunner(BackupRepositorySettings $createBackup): BackupInterface
    {
        switch ($createBackup->getBackupType()) {

            case BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value:
                $runner = new CompleteFileRunner($this->logger, $this->settings);
                break;

            case BackupTypes::COMPLETE_DATABASE_SEPARATE_FILES->value:
                $runner = new CompleteFilesRunner($this->logger, $this->settings, $this->database);
                break;
            case BackupTypes::FILES->value:
                $runner = new FilesRunner(
                    $this->logger,
                    $this->settings,
                    $createBackup,
                    $this->servicesCliOutput
                );
                break;

            default:
                $this->logger->error('Invalid backup type:'.$createBackup->getBackupType());
                throw new InvalidBackupTypeException();
        }

        return $runner;
    }
}
