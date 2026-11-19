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

use Cron\CronExpression;

final class ScheduleCalculator
{
    public function next(string $expression, string $timezone, ?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        if (!CronExpression::isValidExpression($expression)) {
            throw new \InvalidArgumentException('Invalid cron expression.');
        }
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid timezone.', 0, $e);
        }
        $from = ($from ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($zone);
        $next = (new CronExpression($expression))->getNextRunDate($from, 0, false, $zone->getName());
        return \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @return list<\DateTimeImmutable> consecutive runs in UTC
     */
    public function nextRuns(string $expression, string $timezone, int $count, ?\DateTimeImmutable $from = null): array
    {
        $runs = [];
        $cursor = $from;
        for ($i = 0; $i < $count; $i++) {
            $cursor = $this->next($expression, $timezone, $cursor);
            $runs[] = $cursor;
        }
        return $runs;
    }
}
