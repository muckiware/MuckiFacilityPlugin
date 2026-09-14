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

use Psr\Log\LoggerInterface;
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
        protected PluginHelper $pluginHelper,
        protected LoggerInterface $logger
    )
    {}
    
    public function isEnabled(): bool
    {
        return $this->config->getBool(ConfigPath::CONFIG_PATH_ACTIVE->value);
    }

    public function isCompressDbBackupEnabled(): bool
    {
        return $this->config->getBool(ConfigPath::CONFIG_PATH_COMPRESS_DB_BACKUP->value);
    }

    public function getDateTimeStringFormat(): string
    {
        return PluginDefaults::CURRENT_DATETIME_STR_FORMAT;
    }

    public function getDateStringFormat(): string
    {
        return PluginDefaults::CURRENT_DATE_STR_FORMAT;
    }

    public function getDatabaseUrl(): string
    {
        return trim((string) EnvironmentHelper::getVariable('DATABASE_URL', getenv('DATABASE_URL')));
    }

    /**
     * Resolves the target directory for a database dump.
     *
     * Without an own dump path the default path below the project dir is used. An own dump path
     * starting with a slash is taken as it is, any other value is resolved against the project dir.
     *
     * @param bool $useSubFolder Append a sub folder named by the current date
     * @param string|null $ownDumpPath Dump path configured on the backup repository
     */
    public function getBackupPath(bool $useSubFolder=false, ?string $ownDumpPath=null): string
    {
        $backupPath = $this->resolveOwnDumpPath($ownDumpPath);
        if($backupPath === null) {
            return $this->getDefaultBackupPath($useSubFolder);
        }

        if($useSubFolder) {
            $backupPath .= '/'.$this->getDatestamp();
        }

        if(!$this->pluginHelper->ensureDirectoryExists($backupPath)) {
            $this->logger->error(
                'Own database dump path could not be created, using default path instead: '.$backupPath,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            return $this->getDefaultBackupPath($useSubFolder);
        }

        return $backupPath;
    }

    public function getDefaultBackupPath(bool $useSubFolder=false): string
    {
        if($useSubFolder) {
            $backupPath = $this->kernel->getProjectDir().PluginDefaults::DATABASE_BACKUP_PATH.'/'.$this->getDatestamp();
        } else {
            $backupPath = $this->kernel->getProjectDir().PluginDefaults::DATABASE_BACKUP_PATH;
        }

        if(!$this->pluginHelper->ensureDirectoryExists($backupPath)) {
            $this->logger->error(
                'Default database dump path could not be created: '.$backupPath,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }

        return $backupPath;
    }

    /**
     * Returns the absolute dump path for a configured value or null when the default path applies.
     *
     * The dump directory gets removed recursively before and after every database backup, so an
     * unsafe value has to fall back to the default path instead of being used.
     */
    protected function resolveOwnDumpPath(?string $ownDumpPath): ?string
    {
        $ownDumpPath = trim((string) $ownDumpPath);
        if($ownDumpPath === '') {
            return null;
        }

        $ownDumpPath = rtrim($ownDumpPath, '/');
        if($ownDumpPath === '') {
            $this->logger->error(
                'Own database dump path must not be the root directory, using default path instead',
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            return null;
        }

        if($this->hasRelativePathSegment($ownDumpPath)) {
            $this->logger->error(
                'Own database dump path must not contain relative path segments, using default path instead: '.$ownDumpPath,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            return null;
        }

        $projectDir = rtrim($this->kernel->getProjectDir(), '/');
        if(str_starts_with($ownDumpPath, '/')) {
            $resolvedDumpPath = $ownDumpPath;
        } else {
            $resolvedDumpPath = $projectDir.'/'.$ownDumpPath;
        }

        if(str_starts_with($projectDir.'/', $resolvedDumpPath.'/')) {
            $this->logger->error(
                'Own database dump path must not be the project dir or a parent of it, using default path instead: '.$resolvedDumpPath,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            return null;
        }

        return $resolvedDumpPath;
    }

    protected function hasRelativePathSegment(string $path): bool
    {
        foreach (explode('/', $path) as $pathSegment) {
            if($pathSegment === '.' || $pathSegment === '..') {
                return true;
            }
        }

        return false;
    }

    public function getDateTimestamp(): string
    {
        return $this->pluginHelper->getCurrentDateTimeStr($this->getDateTimeStringFormat());
    }

    public function getDatestamp(): string
    {
        return $this->pluginHelper->getCurrentDateTimeStr($this->getDateStringFormat());
    }

    public function hasOwnResticBinaryPath(): bool
    {
        return $this->config->getBool(ConfigPath::CONFIG_USE_OWN_PATH_RESTIC_BINARY->value);
    }

    public function getOwnResticBinaryPath(): ?string
    {
        $ownResctivPath = $this->config->getString(ConfigPath::CONFIG_OWN_PATH_RESTIC_BINARY->value);
        if($ownResctivPath !== '') {
            return $ownResctivPath;
        }
        return null;
    }

    /**
     * Method for to get the number of valid days for cleanup old cart items
     * @return int
     */
    public function getNumberOfValidDaysInCart(): int
    {
        if ($this->config->getInt(ConfigPath::CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_CART->value)) {
            return $this->config->getInt(ConfigPath::CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_CART->value);
        }

        return 30;
    }

    public function getNumberOfValidDaysInLogEntry(): int
    {
        if ($this->config->getInt(ConfigPath::CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_LOG_ENTRY->value)) {
            return $this->config->getInt(ConfigPath::CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_LOG_ENTRY->value);
        }

        return 30;
    }

    public function getLastValidDateForCart(): string
    {
        $validDays = (string)$this->getNumberOfValidDaysInCart();

        return date(
            'Y-m-d',
            strtotime('-'.$validDays.' days', strtotime(date('Y-m-d')))
        );
    }

    public function getLastValidDateForLogEntry(): string
    {
        $validDays = (string)$this->getNumberOfValidDaysInCart();

        return date(
            'Y-m-d',
            strtotime('-'.$validDays.' days', strtotime(date('Y-m-d')))
        );
    }
}
