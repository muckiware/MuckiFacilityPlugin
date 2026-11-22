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
use MuckiFacilityPlugin\Services\SettingsInterface;

final class DueScheduleDispatcher
{
    public const INACTIVE_REPOSITORY_ERROR = 'Backup repository is inactive.';
    public const INACTIVE_PLUGIN_ERROR = 'MuckiFacilityPlugin is inactive.';

    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
        private readonly ScheduleCalculator $calculator,
        private readonly Helper $helper,
        private readonly SettingsInterface $pluginSettings
    ) {}
    public function dispatch(?\DateTimeImmutable $now = null): void
    {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT LOWER(HEX(s.`id`)) id, LOWER(HEX(s.`backup_repository_id`)) repository_id, s.`cron_expression`, s.`timezone`,
COALESCE(r.`active`, 0) repository_active
FROM `muwa_backup_schedule` s
LEFT JOIN `muwa_backup_repository` r ON r.`id` = s.`backup_repository_id`
WHERE s.`enabled` = 1 AND s.`status` IN ('scheduled', 'success', 'failed', 'skipped') AND s.`next_execution_time` <= :now
SQL, ['now' => $now->format('Y-m-d H:i:s.v')]);
        $pluginEnabled = $this->pluginSettings->isEnabled();
        foreach ($rows as $row) {
            $next = $this->calculator->next((string) $row['cron_expression'], (string) $row['timezone'], $now);
            // an inactive plugin or repository never runs scheduled, the due run is skipped instead of caught up later
            if (!$pluginEnabled) {
                $this->skip((string) $row['id'], self::INACTIVE_PLUGIN_ERROR, $next, $now);
                continue;
            }
            if ((int) $row['repository_active'] !== 1) {
                $this->skip((string) $row['id'], self::INACTIVE_REPOSITORY_ERROR, $next, $now);
                continue;
            }
            $token = Uuid::randomHex();
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

    private function skip(string $id, string $error, \DateTimeImmutable $next, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(<<<'SQL'
UPDATE `muwa_backup_schedule` SET `status` = 'skipped', `last_error` = :error,
`next_execution_time` = :next, `updated_at` = :now
WHERE `id` = :id AND `enabled` = 1 AND `status` IN ('scheduled', 'success', 'failed', 'skipped') AND `next_execution_time` <= :now
SQL, ['error'=>$error, 'next'=>$next->format('Y-m-d H:i:s.v'), 'now'=>$now->format('Y-m-d H:i:s.v'), 'id'=>$this->helper->normalizeUuidToBytes($id)]);
    }
}
