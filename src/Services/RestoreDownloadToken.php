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
namespace MuckiFacilityPlugin\Services;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Short-lived, single-use tokens for dump downloads. The download route carries no admin session
 * (the browser navigates to it directly), so the token is the only credential: random, bound to one
 * repository and path, valid for TTL_SECONDS and deleted on first redeem.
 */
class RestoreDownloadToken
{
    public const TTL_SECONDS = 60;
    protected const CACHE_KEY_PREFIX = 'muwa_restore_download_';

    public function __construct(
        protected CacheItemPoolInterface $cache
    )
    {}

    public function issue(string $backupRepositoryId, string $relativePath): string
    {
        $token = bin2hex(random_bytes(32));

        $item = $this->cache->getItem(self::CACHE_KEY_PREFIX . $token);
        $item->set(['backupRepositoryId' => $backupRepositoryId, 'path' => $relativePath]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $token;
    }

    /**
     * @return array{backupRepositoryId: string, path: string}|null
     */
    public function redeem(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $key = self::CACHE_KEY_PREFIX . $token;
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $this->cache->deleteItem($key);
        $value = $item->get();
        if (
            !is_array($value)
            || !is_string($value['backupRepositoryId'] ?? null)
            || !is_string($value['path'] ?? null)
        ) {
            return null;
        }

        return ['backupRepositoryId' => $value['backupRepositoryId'], 'path' => $value['path']];
    }
}
