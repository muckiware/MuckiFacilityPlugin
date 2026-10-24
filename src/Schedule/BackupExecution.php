<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Schedule;

use Symfony\Component\Lock\LockFactory;

use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Services\Backup;

class BackupExecution
{
    public function __construct(
        private readonly Backup $backup,
        private readonly LockFactory $lockFactory
    ) {}

    public function execute(string $repositoryId): void
    {
        $lock = $this->lockFactory->createLock('mucki-facility.backup', null);
        if (!$lock->acquire(false)) {
            throw new BackupAlreadyRunningException();
        }

        try {
            $this->backup->createBackup($this->backup->prepareCreateBackup($repositoryId), false);
        } finally {
            $lock->release();
        }
    }
}
