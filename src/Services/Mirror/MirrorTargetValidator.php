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

use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Core\S3Providers;
use MuckiFacilityPlugin\Exception\InvalidMirrorTargetException;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;

/**
 * Validates the mirror target of a backup repository and detects overlapping targets.
 *
 * The mirror deletes remote objects, so two repositories must never share (parts of) a target.
 */
class MirrorTargetValidator
{
    public function __construct(
        protected S3ConnectionRepository $s3ConnectionRepository,
        protected BackupRepositoryService $backupRepositoryService
    )
    {}

    /**
     * S3 bucket naming rules: 3-63 characters, lowercase letters, digits, dots and hyphens, starting and ending
     * alphanumeric, no consecutive dots, not formatted like an IPv4 address.
     */
    public static function isValidBucketName(string $bucket): bool
    {
        $length = strlen($bucket);
        if ($length < 3 || $length > 63) {
            return false;
        }
        if (preg_match('/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/', $bucket) !== 1) {
            return false;
        }
        if (str_contains($bucket, '..')) {
            return false;
        }

        return preg_match('/\A\d+\.\d+\.\d+\.\d+\z/', $bucket) !== 1;
    }

    /**
     * @param array<string, mixed> $input s3ConnectionId, bucket, prefix
     * @return array{mirrorS3ConnectionId: ?string, mirrorS3Bucket: ?string, mirrorS3Prefix: ?string}
     *
     * @throws InvalidMirrorTargetException
     */
    public function assertValid(array $input): array
    {
        $connectionId = $this->scalarString($input, 's3ConnectionId');
        if ($connectionId === '') {
            return ['mirrorS3ConnectionId' => null, 'mirrorS3Bucket' => null, 'mirrorS3Prefix' => null];
        }

        $bucket = $this->scalarString($input, 'bucket');
        $prefix = $this->scalarString($input, 'prefix');

        if (!Uuid::isValid($connectionId) || $this->s3ConnectionRepository->getById($connectionId) === null) {
            throw new InvalidMirrorTargetException('Unknown S3 connection');
        }
        if (!self::isValidBucketName($bucket)) {
            throw new InvalidMirrorTargetException('Invalid S3 bucket name');
        }

        try {
            $normalizedPrefix = MirrorKeyRules::normalizePrefix($prefix);
        } catch (\InvalidArgumentException) {
            throw new InvalidMirrorTargetException('Invalid path segment in prefix');
        }

        return [
            'mirrorS3ConnectionId' => $connectionId,
            'mirrorS3Bucket' => $bucket,
            'mirrorS3Prefix' => $normalizedPrefix,
        ];
    }

    /**
     * Fail-closed: the mirror deletes remotely, so an unverifiable target counts as a collision.
     *
     * @return ?string name of the colliding repository (also: another repository whose configured connection
     *                 cannot be loaded), null if the target is free
     *
     * @throws InvalidMirrorTargetException if the own connection cannot be loaded
     */
    public function findOverlap(string $repositoryId, string $connectionId, string $bucket, string $prefix): ?string
    {
        $connection = $this->s3ConnectionRepository->getById($connectionId);
        if ($connection === null) {
            throw new InvalidMirrorTargetException('Unknown S3 connection');
        }
        $endpoint = self::endpointKey($connection);

        foreach ($this->backupRepositoryService->findMirrorConfigured($repositoryId) as $other) {
            $otherConnection = $this->s3ConnectionRepository->getById((string) $other->getMirrorS3ConnectionId());
            if ($otherConnection === null) {
                return $other->getName();
            }
            if ($other->getMirrorS3Bucket() !== $bucket || self::endpointKey($otherConnection) !== $endpoint) {
                continue;
            }
            $otherPrefix = (string) $other->getMirrorS3Prefix();
            if (str_starts_with($otherPrefix, $prefix) || str_starts_with($prefix, $otherPrefix)) {
                return $other->getName();
            }
        }

        return null;
    }

    protected static function endpointKey(S3ConnectionEntity $connection): string
    {
        // AWS bucket names are globally unique: every aws target (any region/endpoint) is the same namespace.
        if ($connection->getProvider() === S3Providers::AWS->value) {
            return 'aws';
        }

        // the scheme is ignored: http:// and https:// to the same host[:port][/path] reach the same buckets
        $endpoint = strtolower(trim((string) $connection->getEndpoint()));
        $endpoint = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $endpoint);

        return rtrim($endpoint, '/');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidMirrorTargetException
     */
    protected function scalarString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidMirrorTargetException(sprintf('"%s" must be a string', $key));
        }
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidMirrorTargetException(sprintf('"%s" contains invalid characters', $key));
        }

        return trim($value);
    }
}
