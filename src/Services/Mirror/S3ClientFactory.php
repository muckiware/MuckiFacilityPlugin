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

use Aws\S3\S3Client;

use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Core\S3Providers;

/**
 * Builds an S3 client for a stored connection and its resolved secret access key.
 */
class S3ClientFactory
{
    public const DEFAULT_REGION = 'us-east-1';

    /**
     * @param array<string, mixed> $httpOptions Guzzle request options (e.g. connect_timeout, timeout), merged into the client "http" config
     */
    public function create(
        S3ConnectionEntity $connection,
        #[\SensitiveParameter] string $secret,
        ?callable $handler = null,
        array $httpOptions = []
    ): S3Client {
        $provider = S3Providers::tryFrom($connection->getProvider()) ?? S3Providers::OTHER;
        $region = trim((string) $connection->getRegion());

        $config = [
            'version' => 'latest',
            'region' => $region === '' ? self::DEFAULT_REGION : $region,
            'use_path_style_endpoint' => $provider->usesPathStyle(),
            'credentials' => [
                'key' => $connection->getAccessKeyId(),
                'secret' => $secret,
            ],
        ];

        $endpoint = trim((string) $connection->getEndpoint());
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
        }
        if ($httpOptions !== []) {
            $config['http'] = $httpOptions;
        }
        if ($handler !== null) {
            $config['handler'] = $handler;
        }

        return new S3Client($config);
    }
}
