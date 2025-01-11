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
namespace MuckiFacilityPlugin\Entity;

class BackupRepositorySettings
{
    protected string $backupRepositoryId;
    protected bool $active;
    protected string $name;
    protected string $backupType;

    protected string $repositoryPath;
    protected string $repositoryPassword;
    /**
     * @var BackupPathEntity<array> $backupPaths
     */
    protected array $backupPaths;
    protected string $restorePath;
    protected string $snapshotId;

    protected int $forgetDaily;
    protected int $forgetWeekly;
    protected int $forgetMonthly;
    protected int $forgetYearly;

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function setBackupRepositoryId(string $backupRepositoryId): void
    {
        $this->backupRepositoryId = $backupRepositoryId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getBackupType(): string
    {
        return $this->backupType;
    }

    public function setBackupType(string $backupType): void
    {
        $this->backupType = $backupType;
    }

    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    public function setRepositoryPath(string $repositoryPath): void
    {
        $this->repositoryPath = $repositoryPath;
    }

    public function getRepositoryPassword(): string
    {
        return $this->repositoryPassword;
    }

    public function setRepositoryPassword(string $repositoryPassword): void
    {
        $this->repositoryPassword = $repositoryPassword;
    }

    public function getBackupPaths(): array
    {
        return $this->backupPaths;
    }

    public function setBackupPaths(array $backupPaths): void
    {
        $this->backupPaths = $backupPaths;
    }

    public function getRestorePath(): string
    {
        return $this->restorePath;
    }

    public function setRestorePath(string $restorePath): void
    {
        $this->restorePath = $restorePath;
    }

    public function getSnapshotId(): string
    {
        return $this->snapshotId;
    }

    public function setSnapshotId(string $snapshotId): void
    {
        $this->snapshotId = $snapshotId;
    }

    public function getForgetDaily(): int
    {
        return $this->forgetDaily;
    }

    public function setForgetDaily(int $forgetDaily): void
    {
        $this->forgetDaily = $forgetDaily;
    }

    public function getForgetWeekly(): int
    {
        return $this->forgetWeekly;
    }

    public function setForgetWeekly(int $forgetWeekly): void
    {
        $this->forgetWeekly = $forgetWeekly;
    }

    public function getForgetMonthly(): int
    {
        return $this->forgetMonthly;
    }

    public function setForgetMonthly(int $forgetMonthly): void
    {
        $this->forgetMonthly = $forgetMonthly;
    }

    public function getForgetYearly(): int
    {
        return $this->forgetYearly;
    }

    public function setForgetYearly(int $forgetYearly): void
    {
        $this->forgetYearly = $forgetYearly;
    }
}
