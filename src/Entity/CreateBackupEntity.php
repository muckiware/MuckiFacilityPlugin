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

/**
 *
 */
class CreateBackupEntity
{
    /**
     * @var string
     */
    protected string $backupRepositoryId;
    /**
     * @var string
     */
    protected string $backupType;
    /**
     * @var string
     */
    protected string $repositoryPassword;
    /**
     * @var string
     */
    protected string $repositoryPath;
    /**
     * @var string
     */
    protected string $restoreTarget;
    /**
     * @var string
     */
    protected string $snapshotId;

    /**
     * @var array<BackupPathEntity> $backupPaths
     */
    protected array $backupPaths;

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
     * @return array<mixed>
     */
    public function getBackupPaths(): array
    {
        return $this->backupPaths;
    }

    /**
     * @param array<mixed> $backupPaths
     * @return void
     */
    public function setBackupPaths(array $backupPaths): void
    {
        $this->backupPaths = $backupPaths;
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
    public function getRestoreTarget(): string
    {
        return $this->restoreTarget;
    }

    /**
     * @param string $restoreTarget
     * @return void
     */
    public function setRestoreTarget(string $restoreTarget): void
    {
        $this->restoreTarget = $restoreTarget;
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
}
