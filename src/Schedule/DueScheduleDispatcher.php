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
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

use MuckiFacilityPlugin\Schedule\Message\RunScheduledBackupMessage;
use MuckiFacilityPlugin\Services\Helper;

final class DueScheduleDispatcher
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
        private readonly ScheduleCalculator $calculator,
        private readonly Helper $helper
    ) {}
    public function dispatch(?\DateTimeImmutable $now = null): void
    {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT LOWER(HEX(`id`)) id, LOWER(HEX(`backup_repository_id`)) repository_id, `cron_expression`, `timezone`
FROM `muwa_backup_schedule`
WHERE `enabled` = 1 AND `status` IN ('scheduled', 'success', 'failed', 'skipped') AND `next_execution_time` <= :now
SQL, ['now' => $now->format('Y-m-d H:i:s.v')]);
        foreach ($rows as $row) {
            $token = Uuid::randomHex();
            $next = $this->calculator->next((string) $row['cron_expression'], (string) $row['timezone'], $now);
            $claimed = $this->connection->executeStatement(<<<'SQL'
UPDATE `muwa_backup_schedule` SET `status` = 'queued', `run_token` = :token,
`next_execution_time` = :next, `updated_at` = :now
WHERE `id` = :id AND `enabled` = 1 AND `status` IN ('scheduled', 'success', 'failed', 'skipped') AND `next_execution_time` <= :now
SQL, ['token'=>$token, 'next'=>$next->format('Y-m-d H:i:s.v'), 'now'=>$now->format('Y-m-d H:i:s.v'), 'id'=>$this->helper->normalizeUuidToBytes((string) $row['id'])]);
            if ($claimed === 1) {
                $this->bus->dispatch(new RunScheduledBackupMessage((string) $row['id'], (string) $row['repository_id'], $token));
            }
        }
    }
}
