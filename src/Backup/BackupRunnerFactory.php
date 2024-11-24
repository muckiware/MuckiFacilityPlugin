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
use MuckiFacilityPlugin\Backup\Database\AllDbRunner;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;

class BackupRunnerFactory
{
    public function __construct(
        protected LoggerInterface $logger
    ) {}

    /**
     * @throws InvalidBackupTypeException
     */
    public function createBackupRunner(string $backupType): BackupInterface
    {
        switch ($backupType) {

            case BackupTypes::DATABASE_ALL->value:
                $runner = new AllDbRunner();
                break;
            default:

                $this->logger->error('Invalid backup type:'.$backupType);
                throw new InvalidBackupTypeException();
        }

        return $runner;
    }
}
