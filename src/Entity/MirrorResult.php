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

/**
 * Immutable outcome of one S3 mirror run.
 */
class MirrorResult
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        protected string $status,
        protected string $triggerSource,
        protected ?string $bucket,
        protected ?string $prefix,
        protected ?string $s3ConnectionName,
        protected \DateTimeImmutable $startedAt,
        protected \DateTimeImmutable $finishedAt,
        protected int $filesUploaded = 0,
        protected int $filesDeleted = 0,
        protected int $filesUnchanged = 0,
        protected int $bytesUploaded = 0,
        protected ?string $message = null
    ) {
    }

    public function getStatus(): string { return $this->status; }
    public function getTriggerSource(): string { return $this->triggerSource; }
    public function getBucket(): ?string { return $this->bucket; }
    public function getPrefix(): ?string { return $this->prefix; }
    public function getS3ConnectionName(): ?string { return $this->s3ConnectionName; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): \DateTimeImmutable { return $this->finishedAt; }
    public function getFilesUploaded(): int { return $this->filesUploaded; }
    public function getFilesDeleted(): int { return $this->filesDeleted; }
    public function getFilesUnchanged(): int { return $this->filesUnchanged; }
    public function getBytesUploaded(): int { return $this->bytesUploaded; }
    public function getMessage(): ?string { return $this->message; }
}
