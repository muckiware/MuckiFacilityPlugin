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

use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

/**
 * Derives backup path suggestions from the snapshots of a restic repository.
 * Read only: the paths come from the snapshot `paths` arrays, never from the database column.
 * The repository password never ends up in the returned error message or in log lines.
 */
class BackupPathSuggestions
{
    public const MAX_SUGGESTIONS = 200;

    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepository $backupRepository,
        protected ManageRepository $manageRepository,
        protected SettingsInterface $settings,
        protected PluginHelper $pluginHelper
    )
    {}

    /**
     * @return array{
     *     suggestions: list<array{path: string, lastSeenAt: ?string, hostname: string, snapshotCount: int, state: string, configured: bool}>,
     *     truncated: bool,
     *     error: ?string
     * }
     */
    public function collect(string $backupRepositoryId): array
    {
        try {
            $repository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
            if ($repository === null) {
                $this->logger->error('Backup path suggestions: repository not found', ['repositoryId' => $backupRepositoryId]);
                return $this->failure('Repository not found.');
            }

            $snapshots = $this->manageRepository->readSnapshots(
                $repository->getRepositoryPath(),
                $repository->getRepositoryPassword()
            );
        } catch (\Throwable $exception) {
            // Message is deliberately not logged or returned: it could carry restic output.
            $this->logger->error('Backup path suggestions: repository could not be read', [
                'repositoryId' => $backupRepositoryId,
                'exception' => $exception::class
            ]);
            return $this->failure('The repository could not be read.');
        }

        $excluded = $this->excludedPaths($repository);
        $configured = $this->configuredPaths($repository->getBackupPaths());
        $entries = $this->aggregate($snapshots, $excluded);
        $this->sortEntries($entries);

        $truncated = count($entries) > self::MAX_SUGGESTIONS;
        $suggestions = [];
        foreach (array_slice($entries, 0, self::MAX_SUGGESTIONS) as $entry) {
            $suggestions[] = [
                'path' => $entry['path'],
                'lastSeenAt' => $entry['time']?->format(\DATE_ATOM),
                'hostname' => $entry['hostname'],
                'snapshotCount' => $entry['count'],
                'state' => $this->pathState($entry['path']),
                'configured' => isset($configured[$this->trimTrailingSlash($entry['path'])])
            ];
        }

        return ['suggestions' => $suggestions, 'truncated' => $truncated, 'error' => null];
    }

    /**
     * @return array{suggestions: list<never>, truncated: false, error: string}
     */
    protected function failure(string $message): array
    {
        return ['suggestions' => [], 'truncated' => false, 'error' => $message];
    }

    /**
     * @param array<int, mixed> $snapshots
     * @param array<string, true> $excluded
     * @return array<string, array{path: string, time: ?\DateTime, hostname: string, count: int}>
     */
    protected function aggregate(array $snapshots, array $excluded): array
    {
        $entries = [];
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot) || !is_array($snapshot['paths'] ?? null)) {
                continue;
            }
            $time = is_string($snapshot['time'] ?? null)
                ? $this->pluginHelper->createDateTimeFromString($snapshot['time'])
                : null;
            $hostname = is_string($snapshot['hostname'] ?? null) ? $snapshot['hostname'] : '';

            foreach (array_unique(array_filter($snapshot['paths'], 'is_string')) as $path) {
                if ($path === '' || isset($excluded[$this->trimTrailingSlash($path)])) {
                    continue;
                }
                $entries[$path] = $this->mergeEntry($entries[$path] ?? null, $path, $time, $hostname);
            }
        }

        return $entries;
    }

    /**
     * @param array{path: string, time: ?\DateTime, hostname: string, count: int}|null $entry
     * @return array{path: string, time: ?\DateTime, hostname: string, count: int}
     */
    protected function mergeEntry(?array $entry, string $path, ?\DateTime $time, string $hostname): array
    {
        if ($entry === null) {
            return ['path' => $path, 'time' => $time, 'hostname' => $hostname, 'count' => 1];
        }

        $entry['count']++;
        $isNewer = $time !== null && ($entry['time'] === null || $time > $entry['time']);
        if ($isNewer) {
            $entry['time'] = $time;
            $entry['hostname'] = $hostname;
        } elseif ($entry['time'] === null && $time === null && $entry['hostname'] === '') {
            $entry['hostname'] = $hostname;
        }

        return $entry;
    }

    /**
     * Newest first, entries without a readable time last.
     *
     * @param array<array-key, array{path: string, time: ?\DateTime, hostname: string, count: int}> $entries
     */
    protected function sortEntries(array &$entries): void
    {
        uasort($entries, static function (array $a, array $b): int {
            if ($a['time'] === null || $b['time'] === null) {
                return ($a['time'] === null) <=> ($b['time'] === null) ?: strcmp($a['path'], $b['path']);
            }

            return ($b['time'] <=> $a['time']) ?: strcmp($a['path'], $b['path']);
        });
        $entries = array_values($entries);
    }

    /**
     * @return array<string, true>
     */
    protected function excludedPaths(BackupRepositoryEntity $repository): array
    {
        try {
            $dumpFolder = $this->settings->getBackupPath(
                false,
                $this->readProperty($repository, 'dbDumpPath'),
                $this->readProperty($repository, 'repositoryPath'),
                $this->readProperty($repository, 'restorePath')
            );
        } catch (\Throwable $exception) {
            // Suggestions stay useful without the dump filter, so carry on.
            $this->logger->error('Backup path suggestions: dump folder could not be resolved', [
                'repositoryId' => $repository->getUniqueIdentifier(),
                'exception' => $exception::class
            ]);
            return [];
        }

        return [$this->trimTrailingSlash($dumpFolder) => true];
    }

    /**
     * Typed entity properties stay uninitialized when the column is NULL (a taken-over repository
     * has no restore path), so read them without triggering an \Error.
     */
    protected function readProperty(BackupRepositoryEntity $repository, string $property): ?string
    {
        try {
            $reflection = new \ReflectionProperty($repository, $property);
            if (!$reflection->isInitialized($repository)) {
                return null;
            }
            $value = $reflection->getValue($repository);
        } catch (\ReflectionException) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int, mixed> $backupPaths
     * @return array<string, true>
     */
    protected function configuredPaths(array $backupPaths): array
    {
        $configured = [];
        foreach ($backupPaths as $backupPath) {
            $value = $backupPath instanceof BackupPathEntity
                ? $backupPath->getBackupPath()
                : (is_array($backupPath) ? ($backupPath['backupPath'] ?? null) : null);
            if (is_string($value) && $value !== '') {
                $configured[$this->trimTrailingSlash($value)] = true;
            }
        }

        return $configured;
    }

    /**
     * Logical path check, no realpath().
     */
    protected function pathState(string $path): string
    {
        if (is_dir($path)) {
            return 'directory';
        }

        return is_file($path) ? 'file' : 'missing';
    }

    protected function trimTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? $path : $trimmed;
    }
}
