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
namespace MuckiFacilityPlugin\Backup;

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Backup\Database\CompleteFileRunner;
use MuckiFacilityPlugin\Backup\Database\CompleteFilesRunner;
use MuckiFacilityPlugin\Backup\Database\DumpArchiver;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\DatabaseConnectionResolver;
use MuckiFacilityPlugin\Backup\Files\FilesRunner;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class BackupRunnerFactory
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $settings,
        protected ServicesCliOutput $servicesCliOutput,
        protected DatabaseConnectionResolver $connectionResolver
    ) {}

    /**
     * The database connection is only resolved for the two database backup types.
     *
     * @throws InvalidBackupTypeException
     * @throws DatabaseBackupFailedException
     */
    public function createBackupRunner(BackupRepositorySettings $createBackup): BackupInterface
    {
        switch ($createBackup->getBackupType()) {

            case BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value:
                $runner = new CompleteFileRunner(
                    $this->logger,
                    $this->settings,
                    $createBackup,
                    $this->connectionResolver->resolveForBackup($createBackup),
                    new DumpArchiver($this->logger)
                );
                break;

            case BackupTypes::COMPLETE_DATABASE_SEPARATE_FILES->value:
                $runner = new CompleteFilesRunner(
                    $this->logger,
                    $this->settings,
                    $createBackup,
                    $this->connectionResolver->resolveForBackup($createBackup),
                    new DumpArchiver($this->logger)
                );
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
