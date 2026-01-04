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
namespace MuckiFacilityPlugin\Entity;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

/**
 *
 */
class BackupRepositorySettings
{
    /**
     * @var string
     */
    protected string $resticPath = 'restic';
    /**
     * @var string
     */
    protected string $backupRepositoryId;
    /**
     * @var bool
     */
    protected bool $active;
    /**
     * @var string
     */
    protected string $name;
    /**
     * @var string
     */
    protected string $backupType;

    /**
     * @var string
     */
    protected string $repositoryPath;
    /**
     * @var string
     */
    protected string $repositoryPassword;
    /**
     * @var array<BackupPathEntity> $backupPaths
     */
    protected array $backupPaths;
    /**
     * @var string
     */
    protected string $restorePath;
    /**
     * @var string
     */
    protected string $snapshotId;

    /**
     * @var int
     */
    protected int $forgetDaily;
    /**
     * @var int
     */
    protected int $forgetWeekly;
    /**
     * @var int
     */
    protected int $forgetMonthly;
    /**
     * @var int
     */
    protected int $forgetYearly;

    protected string $hostName = PluginDefaults::DEFAULT_REPOSITORY_HOST_NAME;

    /**
     * @return string
     */
    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    /**
     * @param string $backupRepositoryId
     * @return void
     */
    public function setBackupRepositoryId(string $backupRepositoryId): void
    {
        $this->backupRepositoryId = $backupRepositoryId;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active
     * @return void
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return void
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getBackupType(): string
    {
        return $this->backupType;
    }

    /**
     * @param string $backupType
     * @return void
     */
    public function setBackupType(string $backupType): void
    {
        $this->backupType = $backupType;
    }

    /**
     * @return string
     */
    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    /**
     * @param string $repositoryPath
     * @return void
     */
    public function setRepositoryPath(string $repositoryPath): void
    {
        $this->repositoryPath = $repositoryPath;
    }

    /**
     * @return string
     */
    public function getRepositoryPassword(): string
    {
        return $this->repositoryPassword;
    }

    /**
     * @param string $repositoryPassword
     * @return void
     */
    public function setRepositoryPassword(string $repositoryPassword): void
    {
        $this->repositoryPassword = $repositoryPassword;
    }

    /**
     * @return array<BackupPathEntity>
     */
    public function getBackupPaths(): array
    {
        return $this->backupPaths;
    }

    /**
     * @param array<BackupPathEntity> $backupPaths
     * @return void
     */
    public function setBackupPaths(array $backupPaths): void
    {
        $this->backupPaths = $backupPaths;
    }

    /**
     * @return string
     */
    public function getRestorePath(): string
    {
        return $this->restorePath;
    }

    /**
     * @param string $restorePath
     * @return void
     */
    public function setRestorePath(string $restorePath): void
    {
        $this->restorePath = $restorePath;
    }

    /**
     * @return string
     */
    public function getSnapshotId(): string
    {
        return $this->snapshotId;
    }

    /**
     * @param string $snapshotId
     * @return void
     */
    public function setSnapshotId(string $snapshotId): void
    {
        $this->snapshotId = $snapshotId;
    }

    /**
     * @return int
     */
    public function getForgetDaily(): int
    {
        return $this->forgetDaily;
    }

    /**
     * @param int $forgetDaily
     * @return void
     */
    public function setForgetDaily(int $forgetDaily): void
    {
        $this->forgetDaily = $forgetDaily;
    }

    /**
     * @return int
     */
    public function getForgetWeekly(): int
    {
        return $this->forgetWeekly;
    }

    /**
     * @param int $forgetWeekly
     * @return void
     */
    public function setForgetWeekly(int $forgetWeekly): void
    {
        $this->forgetWeekly = $forgetWeekly;
    }

    /**
     * @return int
     */
    public function getForgetMonthly(): int
    {
        return $this->forgetMonthly;
    }

    /**
     * @param int $forgetMonthly
     * @return void
     */
    public function setForgetMonthly(int $forgetMonthly): void
    {
        $this->forgetMonthly = $forgetMonthly;
    }

    /**
     * @return int
     */
    public function getForgetYearly(): int
    {
        return $this->forgetYearly;
    }

    /**
     * @param int $forgetYearly
     * @return void
     */
    public function setForgetYearly(int $forgetYearly): void
    {
        $this->forgetYearly = $forgetYearly;
    }

    /**
     * @return string
     */
    public function getResticPath(): string
    {
        return $this->resticPath;
    }

    /**
     * @param string $resticPath
     * @return void
     */
    public function setResticPath(string $resticPath): void
    {
        $this->resticPath = $resticPath;
    }

    public function getHostName(): string
    {
        return $this->hostName;
    }

    public function setHostName(string $hostName): void
    {
        $this->hostName = $hostName;
    }
}
