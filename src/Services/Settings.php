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

use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Core\ConfigPath;

class Settings implements SettingsInterface
{
    public function __construct(
        protected SystemConfigService $config,
        protected KernelInterface $kernel,
        protected PluginHelper $pluginHelper
    )
    {}
    
    public function isEnabled(): bool
    {
        return $this->config->getBool(ConfigPath::CONFIG_PATH_ACTIVE->value);
    }

    public function getDateTimeStringFormat(): string
    {
        return PluginDefaults::CURRENT_DATETIME_STR_FORMAT;
    }

    public function getDatabaseUrl(): string
    {
        return trim((string) EnvironmentHelper::getVariable('DATABASE_URL', getenv('DATABASE_URL')));
    }

    public function getBackupPath(): string
    {
        return $this->getDefaultBackupPath();
    }

    public function getDefaultBackupPath(): string
    {
        $backupPath = $this->kernel->getProjectDir().PluginDefaults::BACKUP_PATH;
        $this->pluginHelper->ensureDirectoryExists($backupPath);
        return $backupPath;
    }

    public function getDateTimestamp(): string
    {
        return $this->pluginHelper->getCurrentDateTimeStr($this->getDateTimeStringFormat());
    }
}
