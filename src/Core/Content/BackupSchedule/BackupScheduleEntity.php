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
namespace MuckiFacilityPlugin\Core\Content\BackupSchedule;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;

class BackupScheduleEntity extends Entity
{
    use EntityIdTrait;
    protected string $backupRepositoryId;
    protected bool $enabled = false;
    protected string $cronExpression;
    protected string $timezone = 'UTC';
    protected string $status = 'inactive';
    protected ?\DateTimeInterface $lastExecutionTime = null;
    protected ?\DateTimeInterface $nextExecutionTime = null;
    protected ?string $lastError = null;
    protected ?string $runToken = null;
    protected ?BackupRepositoryEntity $backupRepository = null;

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }
    public function setBackupRepositoryId(string $v): void
    {
        $this->backupRepositoryId = $v;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function setEnabled(bool $v): void
    {
        $this->enabled = $v;
    }
    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }
    public function setCronExpression(string $v): void
    {
        $this->cronExpression = $v;
    }
    public function getTimezone(): string
    {
        return $this->timezone;
    }
    public function setTimezone(string $v): void
    {
        $this->timezone = $v;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $v): void
    {
        $this->status = $v;
    }
    public function getLastExecutionTime(): ?\DateTimeInterface
    {
        return $this->lastExecutionTime;
    }
    public function setLastExecutionTime(?\DateTimeInterface $v): void
    {
        $this->lastExecutionTime = $v;
    }
    public function getNextExecutionTime(): ?\DateTimeInterface
    {
        return $this->nextExecutionTime;
    }
    public function setNextExecutionTime(?\DateTimeInterface $v): void
    {
        $this->nextExecutionTime = $v;
    }
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
    public function setLastError(?string $v): void
    {
        $this->lastError = $v;
    }
    public function getRunToken(): ?string
    {
        return $this->runToken;
    }
    public function setRunToken(?string $v): void
    {
        $this->runToken = $v;
    }
    public function getBackupRepository(): ?BackupRepositoryEntity
    {
        return $this->backupRepository;
    }
    public function setBackupRepository(?BackupRepositoryEntity $v): void
    {
        $this->backupRepository = $v;
    }
}
