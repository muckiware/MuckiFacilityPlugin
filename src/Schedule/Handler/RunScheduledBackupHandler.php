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
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Schedule\Exception\BackupAlreadyRunningException;
use MuckiFacilityPlugin\Schedule\Message\RunScheduledBackupMessage;

final class RunScheduledBackupHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BackupExecution $execution
    ) {}

    public function __invoke(RunScheduledBackupMessage $message): void
    {
        $id = $this->bytes($message->scheduleId);
        $row = $this->connection->fetchAssociative(
            'SELECT `run_token`, `status` FROM `muwa_backup_schedule` WHERE `id` = :id',
            ['id' => $id]
        );

        if (!$row || $row['status'] !== 'queued'
            || !hash_equals((string) $row['run_token'], $message->runToken)) {
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

    private function bytes(string $id): string
    {
        return strlen($id) === 32 && ctype_xdigit($id) ? Uuid::fromHexToBytes($id) : $id;
    }
}
