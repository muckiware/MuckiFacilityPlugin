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

use MuckiRestic\Entity\Result\ResultEntity;

/**
 *
 */
interface BackupInterface
{
    /**
     * @return mixed
     */
    public function getBackupData(): mixed;

    /**
     * @param mixed $data
     * @return void
     */
    public function saveBackupData(mixed $data): void;

    /**
     * @return void
     */
    public function removeBackupData(): void;

    /**
     * @return void
     */
    public function checkBackupData(): void;

    /**
     * @return array<ResultEntity>
     */
    public function getBackupResults(): array;

    /**
     * @param bool $isJsonOutput
     * @return void
     */
    public function createBackupData(bool $isJsonOutput=true): void;
}
