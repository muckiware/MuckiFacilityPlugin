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
namespace MuckiFacilityPlugin\Services\Mirror;

use Symfony\Component\Lock\LockInterface;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Entity\MirrorResult;

/**
 * Mutable state of one mirror run: target, held lock and counters. Holds no secret.
 */
class MirrorRun
{
    protected \DateTimeImmutable $startedAt;

    protected string $prefix;

    protected int $filesUploaded = 0;

    protected int $filesDeleted = 0;

    protected int $filesUnchanged = 0;

    protected int $bytesUploaded = 0;

    protected float $lastRefreshAt = 0.0;

    public function __construct(
        protected BackupRepositoryEntity $repository,
        protected S3ConnectionEntity $connection,
        protected string $triggerSource,
        protected LockInterface $lock
    ) {
        $this->startedAt = new \DateTimeImmutable();
        try {
            $this->prefix = MirrorKeyRules::normalizePrefix($repository->getMirrorS3Prefix());
        } catch (\InvalidArgumentException) {
            $this->prefix = (string) $repository->getMirrorS3Prefix(); // rejected again in the preconditions
        }
    }

    public function getRepository(): BackupRepositoryEntity { return $this->repository; }
    public function getConnection(): S3ConnectionEntity { return $this->connection; }
    public function getLock(): LockInterface { return $this->lock; }
    public function getTriggerSource(): string { return $this->triggerSource; }
    public function getPrefix(): string { return $this->prefix; }
    public function setPrefix(string $prefix): void { $this->prefix = $prefix; }
    public function getLastRefreshAt(): float { return $this->lastRefreshAt; }
    public function setLastRefreshAt(float $lastRefreshAt): void { $this->lastRefreshAt = $lastRefreshAt; }
    public function setFilesUnchanged(int $filesUnchanged): void { $this->filesUnchanged = $filesUnchanged; }

    public function getBucket(): string
    {
        return trim((string) $this->repository->getMirrorS3Bucket());
    }

    public function getPath(): string
    {
        return rtrim($this->repository->getRepositoryPath(), '/');
    }

    /**
     * @return int number of files uploaded so far
     */
    public function recordUpload(int $bytes): int
    {
        $this->bytesUploaded += $bytes;

        return ++$this->filesUploaded;
    }

    public function recordDeleted(int $count): void
    {
        $this->filesDeleted += $count;
    }

    public function toResult(string $status, ?string $message): MirrorResult
    {
        $bucket = $this->getBucket();

        return new MirrorResult(
            status: $status,
            triggerSource: $this->triggerSource,
            bucket: $bucket === '' ? null : $bucket,
            prefix: $this->prefix,
            s3ConnectionName: $this->connection->getName(),
            startedAt: $this->startedAt,
            finishedAt: new \DateTimeImmutable(),
            filesUploaded: $this->filesUploaded,
            filesDeleted: $this->filesDeleted,
            filesUnchanged: $this->filesUnchanged,
            bytesUploaded: $this->bytesUploaded,
            message: $message
        );
    }
}
