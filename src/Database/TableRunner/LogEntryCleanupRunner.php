<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Database\TableRunner;

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;

class LogEntryCleanupRunner implements TableCleanupInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected SettingsInterface $pluginSettings
    ) {}

    public function getCreateTableStatement(): string
    {
        return '';
    }

    public function checkOldTempTable(): void
    {
        // TODO: Implement checkOldTempTable() method.
    }

    public function removeOldTableItems(): void
    {
        // TODO: Implement removeOldTableItems() method.
    }

    public function createTempTable(): bool
    {
        return false;
    }

    public function copyTableItemsIntoTempTable(): void
    {
        // TODO: Implement copyTableItemsIntoTempTable() method.
    }

    public function countTableItemsInTempTable(): ?int
    {
        return null;
    }

    public function removeTableByName(): void
    {
        // TODO: Implement removeTableByName() method.
    }

    public function createNewTable(): void
    {
        // TODO: Implement createNewTable() method.
    }

    public function insertCartItemsFromTempTable(): void
    {
        // TODO: Implement insertCartItemsFromTempTable() method.
    }
}
