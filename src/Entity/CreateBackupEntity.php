<?php

namespace MuckiFacilityPlugin\Entity;



class CreateBackupEntity
{
    protected string $backupRepositoryId;
    protected string $backupType;
    protected string $repositoryPassword;
    protected string $repositoryPath;
    protected string $restoreTarget;
    protected string $snapshotId;

    /**
     * @var BackupPathEntity<array> $backupPaths
     */
    protected array $backupPaths;

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function setBackupRepositoryId(string $backupRepositoryId): void
    {
        $this->backupRepositoryId = $backupRepositoryId;
    }

    public function getRepositoryPassword(): string
    {
        return $this->repositoryPassword;
    }

    public function setRepositoryPassword(string $repositoryPassword): void
    {
        $this->repositoryPassword = $repositoryPassword;
    }

    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    public function setRepositoryPath(string $repositoryPath): void
    {
        $this->repositoryPath = $repositoryPath;
    }

    public function getBackupPaths(): array
    {
        return $this->backupPaths;
    }

    public function setBackupPaths(array $backupPaths): void
    {
        $this->backupPaths = $backupPaths;
    }

    public function getBackupType(): string
    {
        return $this->backupType;
    }

    public function setBackupType(string $backupType): void
    {
        $this->backupType = $backupType;
    }

    public function getRestoreTarget(): string
    {
        return $this->restoreTarget;
    }

    public function setRestoreTarget(string $restoreTarget): void
    {
        $this->restoreTarget = $restoreTarget;
    }

    public function getSnapshotId(): string
    {
        return $this->snapshotId;
    }

    public function setSnapshotId(string $snapshotId): void
    {
        $this->snapshotId = $snapshotId;
    }
}
