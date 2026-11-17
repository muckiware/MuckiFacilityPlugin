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
namespace MuckiFacilityPlugin\Core;

/**
 * Supported S3 providers. Controls addressing style and which connection fields are required.
 */
enum S3Providers: string
{
    case AWS = 'aws';
    case MINIO = 'minio';
    case CEPH = 'ceph';
    case OTHER = 'other';

    public function usesPathStyle(): bool
    {
        return $this !== self::AWS;
    }

    public function requiresRegion(): bool
    {
        return $this === self::AWS;
    }

    public function requiresEndpoint(): bool
    {
        return $this !== self::AWS;
    }
}
