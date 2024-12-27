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
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
use MuckiFacilityPlugin\Backup\Files\FilesRunner;

class BackupRunnerFactory
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $settings,
        protected CoreDatabase $database
    ) {}

    /**
     * @throws InvalidBackupTypeException
     */
    public function createBackupRunner(string $backupType): BackupInterface
    {
        switch ($backupType) {

            case BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value:
                $runner = new CompleteFileRunner($this->logger, $this->settings);
                break;

            case BackupTypes::COMPLETE_DATABASE_SEPARATE_FILES->value:
                $runner = new CompleteFilesRunner($this->logger, $this->settings, $this->database);
                break;
            case BackupTypes::FILES->value:
                $runner = new FilesRunner($this->logger, $this->settings);
                break;

            default:
                $this->logger->error('Invalid backup type:'.$backupType);
                throw new InvalidBackupTypeException();
        }

        return $runner;
    }
}
