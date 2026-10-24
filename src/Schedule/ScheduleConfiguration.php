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

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryNotFoundException;
use Shopware\Core\Framework\Uuid\Uuid;

final class ScheduleConfiguration
{
    public function __construct(private readonly Connection $connection, private readonly ScheduleCalculator $calculator) {}

    public function configure(string $repositoryId, bool $enabled, string $cronExpression, string $timezone, ?\DateTimeImmutable $now = null): void
    {
        // Validation is required even while disabled so an invalid configuration is never persisted.
        $next = $this->calculator->next($cronExpression, $timezone, $now);

        if (!Uuid::isValid($repositoryId)) {
            throw new \InvalidArgumentException('Invalid backup repository id.');
        }

        $repositoryIdBytes = Uuid::fromHexToBytes($repositoryId);
        $repositoryExists = $this->connection->fetchOne(
            'SELECT 1 FROM `muwa_backup_repository` WHERE `id` = :id',
            ['id' => $repositoryIdBytes]
        );
        if ($repositoryExists === false) {
            throw new BackupRepositoryNotFoundException($repositoryId);
        }

        $params = [
            'id' => Uuid::randomBytes(),
            'repositoryId' => $repositoryIdBytes,
            'enabled' => $enabled ? 1 : 0,
            'cronExpression' => $cronExpression,
            'timezone' => $timezone,
            'status' => $enabled ? 'scheduled' : 'inactive',
            'nextExecutionTime' => $enabled ? $next->format('Y-m-d H:i:s.v') : null,
            'runToken' => null,
            'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ];
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO `muwa_backup_schedule`
(`id`,`backup_repository_id`,`enabled`,`cron_expression`,`timezone`,`status`,`next_execution_time`,`run_token`,`created_at`)
VALUES (:id,:repositoryId,:enabled,:cronExpression,:timezone,:status,:nextExecutionTime,:runToken,:createdAt)
ON DUPLICATE KEY UPDATE `enabled`=VALUES(`enabled`), `cron_expression`=VALUES(`cron_expression`),
`timezone`=VALUES(`timezone`), `status`=VALUES(`status`), `next_execution_time`=VALUES(`next_execution_time`),
`run_token`=NULL, `last_error`=NULL, `updated_at`=VALUES(`created_at`)
SQL, $params);
    }
}
