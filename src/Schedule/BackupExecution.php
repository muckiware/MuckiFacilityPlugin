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

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;

use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Services\Backup;

class BackupExecution
{
    public function __construct(
        private readonly Backup $backup,
        private readonly LockFactory $lockFactory,
        private readonly NotificationService $notificationService
    ) {}

    public function execute(string $repositoryId): void
    {
        $lock = $this->lockFactory->createLock('mucki-facility.backup', null);
        if (!$lock->acquire(false)) {
            throw new BackupAlreadyRunningException();
        }

        $context = Context::createDefaultContext();

        try {
            $settings = $this->backup->prepareCreateBackup($repositoryId);
            $this->notify(
                'success',
                'muwa-backup-repository.notification.backupStarted',
                ['name' => $settings->getName()],
                $context
            );

            try {
                $this->backup->createBackup($settings, false);
            } catch (\Throwable $exception) {
                $this->notify(
                    'error',
                    'muwa-backup-repository.notification.backupFailed',
                    ['name' => $settings->getName(), 'error' => $exception->getMessage()],
                    $context
                );
                throw $exception;
            }

            $this->notify(
                'info',
                'muwa-backup-repository.notification.backupSucceeded',
                ['name' => $settings->getName()],
                $context
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function notify(string $status, string $snippet, array $parameters, Context $context): void
    {
        $message = json_encode([
            'source' => 'mucki-facility',
            'snippet' => $snippet,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->notificationService->createNotification([
            'id' => Uuid::randomHex(),
            'status' => $status,
            'message' => $message,
            'adminOnly' => true,
            'requiredPrivileges' => ['muwa_backup_repository:backup'],
        ], $context);
    }
}
