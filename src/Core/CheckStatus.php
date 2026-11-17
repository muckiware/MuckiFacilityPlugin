<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core;

/**
 * Interprets the check status text of a restic repository check.
 */
final class CheckStatus
{
    private const HEALTHY_MARKER = 'no errors were found';

    public static function isHealthy(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        return str_contains(strtolower($status), self::HEALTHY_MARKER);
    }
}
