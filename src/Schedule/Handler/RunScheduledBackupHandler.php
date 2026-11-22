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
namespace MuckiFacilityPlugin\Schedule\Handler;

use Doctrine\DBAL\Connection;

use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\DueScheduleDispatcher;
use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryInactiveException;
use MuckiFacilityPlugin\Schedule\Message\RunScheduledBackupMessage;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\SettingsInterface;

final class RunScheduledBackupHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BackupExecution $execution,
        private readonly Helper $helper,
        private readonly SettingsInterface $pluginSettings
    ) {}

    public function __invoke(RunScheduledBackupMessage $message): void
    {
        $id = $this->helper->normalizeUuidToBytes($message->scheduleId);
        $row = $this->connection->fetchAssociative(
            'SELECT s.`run_token`, s.`status`, COALESCE(r.`active`, 0) repository_active
             FROM `muwa_backup_schedule` s
             LEFT JOIN `muwa_backup_repository` r ON r.`id` = s.`backup_repository_id`
             WHERE s.`id` = :id',
            ['id' => $id]
        );

        if (!$row || $row['status'] !== 'queued'
            || !hash_equals((string) $row['run_token'], $message->runToken)) {
            return;
        }

        // the plugin or the repository may have been deactivated after the run was queued
        if (!$this->pluginSettings->isEnabled()) {
            $this->finish($id, $message->runToken, 'skipped', DueScheduleDispatcher::INACTIVE_PLUGIN_ERROR);
            return;
        }
        if ((int) $row['repository_active'] !== 1) {
            $this->finish($id, $message->runToken, 'skipped', DueScheduleDispatcher::INACTIVE_REPOSITORY_ERROR);
            return;
        }

        try {
            $running = $this->connection->executeStatement(
                "UPDATE `muwa_backup_schedule` SET `status` = 'running', `updated_at` = :now
                 WHERE `id` = :id AND `status` = 'queued' AND `run_token` = :token",
                ['now' => $this->now(), 'id' => $id, 'token' => $message->runToken]
            );
            if ($running !== 1) {
                return;
            }

            $this->execution->execute($message->repositoryId);
            $this->finish($id, $message->runToken, 'success', null);
        } catch (BackupAlreadyRunningException $e) {
            $this->finish($id, $message->runToken, 'skipped', $e->getMessage());
        } catch (BackupRepositoryInactiveException) {
            $this->finish($id, $message->runToken, 'skipped', DueScheduleDispatcher::INACTIVE_REPOSITORY_ERROR);
        } catch (\Throwable $e) {
            $this->finish($id, $message->runToken, 'failed', mb_substr($e->getMessage(), 0, 65535));
        }
    }

    private function finish(string $id, string $token, string $status, ?string $error): void
    {
        $this->connection->executeStatement(
            'UPDATE `muwa_backup_schedule` SET `status` = :status, `last_execution_time` = :now,
             `last_error` = :error, `run_token` = NULL, `updated_at` = :now
             WHERE `id` = :id AND `run_token` = :token',
            ['status' => $status, 'now' => $this->now(), 'error' => $error, 'id' => $id, 'token' => $token]
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
