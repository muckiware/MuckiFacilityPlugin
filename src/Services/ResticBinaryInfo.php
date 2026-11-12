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

use MuckiRestic\Entity\Result\ResticResponse\Version;
use MuckiRestic\Library\Manage;

/**
 * Reports which restic binary the plugin uses with the saved configuration, and its version.
 *
 * The binary is chosen the same way as in the backup, restore and manage services: the own path
 * when "use own restic path" is enabled and set, otherwise `restic` from PATH.
 */
class ResticBinaryInfo
{
    public const DEFAULT_BINARY = 'restic';

    /**
     * muckiware/restic parses `restic init --json`, which restic only answers in JSON from 0.15 on.
     */
    public const MIN_SUPPORTED_VERSION = '0.15.0';

    public function __construct(
        protected SettingsInterface $settings
    ) {}

    /**
     * @return array{
     *     configuredPath: string,
     *     ownPath: bool,
     *     path: ?string,
     *     version: ?string,
     *     minVersion: string,
     *     supported: bool,
     *     error: ?string
     * }
     */
    public function getInfo(): array
    {
        $configuredPath = $this->getConfiguredBinaryPath();
        $info = [
            'configuredPath' => $configuredPath,
            'ownPath' => $configuredPath !== self::DEFAULT_BINARY,
            'path' => $this->resolveBinaryPath($configuredPath),
            'version' => null,
            'minVersion' => self::MIN_SUPPORTED_VERSION,
            'supported' => false,
            'error' => null,
        ];

        if ($info['path'] === null) {
            $info['error'] = 'notFound';
            return $info;
        }

        try {
            $info['version'] = $this->requestVersion($info['path']);
        } catch (\Throwable) {
            $info['error'] = 'versionUnreadable';
            return $info;
        }

        $info['supported'] = version_compare($info['version'], self::MIN_SUPPORTED_VERSION, '>=');

        return $info;
    }

    public function getConfiguredBinaryPath(): string
    {
        if (!$this->settings->hasOwnResticBinaryPath()) {
            return self::DEFAULT_BINARY;
        }

        return $this->settings->getOwnResticBinaryPath() ?? self::DEFAULT_BINARY;
    }

    /**
     * Same lookup as muckiware/restic: a value with a separator is taken as given, a bare name is
     * searched in PATH.
     */
    public function resolveBinaryPath(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/') || str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = $directory.DIRECTORY_SEPARATOR.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @throws \Exception
     */
    protected function requestVersion(string $binaryPath): string
    {
        $manageClient = Manage::create();
        $manageClient->setBinaryPath($binaryPath);

        $version = $manageClient->getResticVersion()->getResticResponse();
        if (!$version instanceof Version) {
            throw new \RuntimeException('restic version could not be read');
        }

        return $version->getVersion();
    }
}
