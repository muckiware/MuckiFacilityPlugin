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
namespace MuckiFacilityPlugin\Backup\Database;

/**
 * Database dump runners report dump errors they only logged (default connection), so
 * Services\Backup can make them visible (red check entry, event, optional abort).
 */
interface DumpFailureAwareInterface
{
    /**
     * Password-masked error messages of the last createBackupData() run, empty when the dump succeeded.
     *
     * @return array<int, string>
     */
    public function getDumpFailures(): array;
}
