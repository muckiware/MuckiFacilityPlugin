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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Stats;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BackupRepositoryStatsEntity extends Entity
{
    use EntityIdTrait;

    protected string $backupRepositoryId;
    protected ?int $totalSize = null;
    protected ?int $totalFileCount = null;
    protected ?int $snapshotsCount = null;
    protected ?int $fileSystemSize = null;
    protected ?string $checkStatus = null;

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function setBackupRepositoryId(string $backupRepositoryId): void
    {
        $this->backupRepositoryId = $backupRepositoryId;
    }

    public function getTotalSize(): ?int
    {
        return $this->totalSize;
    }

    public function setTotalSize(?int $totalSize): void
    {
        $this->totalSize = $totalSize;
    }

    public function getTotalFileCount(): ?int
    {
        return $this->totalFileCount;
    }

    public function setTotalFileCount(?int $totalFileCount): void
    {
        $this->totalFileCount = $totalFileCount;
    }

    public function getSnapshotsCount(): ?int
    {
        return $this->snapshotsCount;
    }

    public function setSnapshotsCount(?int $snapshotsCount): void
    {
        $this->snapshotsCount = $snapshotsCount;
    }

    public function getFileSystemSize(): ?int
    {
        return $this->fileSystemSize;
    }

    public function setFileSystemSize(?int $fileSystemSize): void
    {
        $this->fileSystemSize = $fileSystemSize;
    }

    public function getCheckStatus(): ?string
    {
        return $this->checkStatus;
    }

    public function setCheckStatus(?string $checkStatus): void
    {
        $this->checkStatus = $checkStatus;
    }
}
