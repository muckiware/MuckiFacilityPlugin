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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Uploads;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BackupRepositoryUploadsEntity extends Entity
{
    use EntityIdTrait;

    protected string $backupRepositoryId;

    /**
     * @var string succeeded | failed | skipped
     */
    protected string $status;

    /**
     * @var string backup | cli
     */
    protected string $triggerSource;

    protected ?string $bucket = null;
    protected ?string $prefix = null;
    protected ?string $s3ConnectionName = null;
    protected ?\DateTimeInterface $startedAt = null;
    protected ?\DateTimeInterface $finishedAt = null;
    protected ?int $filesUploaded = null;
    protected ?int $filesDeleted = null;
    protected ?int $filesUnchanged = null;
    protected ?int $bytesUploaded = null;
    protected ?string $message = null;

    public function getBackupRepositoryId(): string { return $this->backupRepositoryId; }
    public function setBackupRepositoryId(string $backupRepositoryId): void { $this->backupRepositoryId = $backupRepositoryId; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }

    public function getTriggerSource(): string { return $this->triggerSource; }
    public function setTriggerSource(string $triggerSource): void { $this->triggerSource = $triggerSource; }

    public function getBucket(): ?string { return $this->bucket; }
    public function setBucket(?string $bucket): void { $this->bucket = $bucket; }

    public function getPrefix(): ?string { return $this->prefix; }
    public function setPrefix(?string $prefix): void { $this->prefix = $prefix; }

    public function getS3ConnectionName(): ?string { return $this->s3ConnectionName; }
    public function setS3ConnectionName(?string $s3ConnectionName): void { $this->s3ConnectionName = $s3ConnectionName; }

    public function getStartedAt(): ?\DateTimeInterface { return $this->startedAt; }
    public function setStartedAt(?\DateTimeInterface $startedAt): void { $this->startedAt = $startedAt; }

    public function getFinishedAt(): ?\DateTimeInterface { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeInterface $finishedAt): void { $this->finishedAt = $finishedAt; }

    public function getFilesUploaded(): ?int { return $this->filesUploaded; }
    public function setFilesUploaded(?int $filesUploaded): void { $this->filesUploaded = $filesUploaded; }

    public function getFilesDeleted(): ?int { return $this->filesDeleted; }
    public function setFilesDeleted(?int $filesDeleted): void { $this->filesDeleted = $filesDeleted; }

    public function getFilesUnchanged(): ?int { return $this->filesUnchanged; }
    public function setFilesUnchanged(?int $filesUnchanged): void { $this->filesUnchanged = $filesUnchanged; }

    public function getBytesUploaded(): ?int { return $this->bytesUploaded; }
    public function setBytesUploaded(?int $bytesUploaded): void { $this->bytesUploaded = $bytesUploaded; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): void { $this->message = $message; }
}
