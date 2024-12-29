<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Backup;

interface BackupInterface
{
    public function getBackupData(): mixed;

    public function saveBackupData(mixed $data): void;

    public function removeBackupData(): void;

    public function checkBackupData(): string;
}