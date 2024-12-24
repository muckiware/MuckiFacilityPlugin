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
namespace MuckiFacilityPlugin\Services;

use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiLogPlugin\Core\LogLevel;

interface SettingsInterface
{
    public function isEnabled(): bool;
    public function isCompressDbBackupEnabled(): bool;
    public function getDatabaseUrl(): string;
    public function getBackupPath(): string;
    public function getDateTimestamp(): string;
    public function getDatestamp(): string;
}
