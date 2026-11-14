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
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Exception\RepositoryTakeoverException;
use MuckiFacilityPlugin\MessageQueue\Message\TakeoverRepositoryCheckMessage;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

/**
 * Adopts an existing restic repository: no init, unlock, prune, forget or backup is run.
 * The repository password never ends up in exception messages, meta data or log lines.
 */
class RepositoryTakeover
{
    public const REQUIRED_WRITABLE_DIRECTORIES = ['locks', 'snapshots', 'index', 'data'];
    public const FALLBACK_NAME = 'restic-repository';
    /** Column length of muwa_backup_repository.repository_path */
    public const MAX_PATH_LENGTH = 255;
    /** Column length of muwa_backup_repository.hostname (and snapshots.hostname) */
    public const MAX_HOSTNAME_LENGTH = 128;
    public const MAX_DETAIL_LENGTH = 300;

    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepository $backupRepository,
        protected BackupFileSnapshotsRepository $backupFileSnapshotsRepository,
        protected ManageRepository $manageRepository,
        protected DirectoryBrowser $directoryBrowser,
        protected PluginHelper $pluginHelper,
        protected MessageBusInterface $messageBus
    )
    {}

    /**
     * @return int number of imported snapshots
     * @throws RepositoryTakeoverException
     */
    public function takeover(string $backupRepositoryId, string $repositoryPath, string $repositoryPassword): int
    {
        $path = $this->validateInput($backupRepositoryId, $repositoryPath, $repositoryPassword);
        $name = basename($path);
        if ($name === '') {
            $name = self::FALLBACK_NAME;
        }

        $this->assertUsableRepositoryFolder($path);
        $this->assertNotYetManaged($path, $name, $backupRepositoryId);
        $storedPassword = $this->prepareStoredPassword($repositoryPassword);
        $snapshots = $this->readSnapshots($path, $repositoryPassword);

        $this->saveRepository(
            $this->createSettings($backupRepositoryId, $name, $path, $repositoryPassword, $snapshots),
            $storedPassword
        );

        $imported = $this->importSnapshots($backupRepositoryId, $snapshots);
        $this->dispatchCheck($backupRepositoryId);

        return $imported;
    }

    /**
     * @throws RepositoryTakeoverException
     */
    protected function validateInput(string $backupRepositoryId, string $repositoryPath, string $repositoryPassword): string
    {
        if (!Uuid::isValid($backupRepositoryId)) {
            throw new RepositoryTakeoverException(RepositoryTakeoverException::INVALID_INPUT, 'Invalid backup repository id');
        }
        if (trim($repositoryPath) === '') {
            throw new RepositoryTakeoverException(RepositoryTakeoverException::INVALID_INPUT, 'Repository path must not be empty');
        }
        if (trim($repositoryPassword) === '') {
            throw new RepositoryTakeoverException(RepositoryTakeoverException::INVALID_INPUT, 'Repository password must not be empty');
        }

        try {
            $path = $this->directoryBrowser->normalizeRepositoryPath($repositoryPath);
        } catch (InvalidBrowsePathException $e) {
            throw new RepositoryTakeoverException(RepositoryTakeoverException::INVALID_INPUT, $e->getMessage(), [], $e);
        }
        if (mb_strlen($path) > self::MAX_PATH_LENGTH) {
            throw new RepositoryTakeoverException(
                RepositoryTakeoverException::INVALID_INPUT,
                'Repository path must not be longer than ' . self::MAX_PATH_LENGTH . ' characters'
            );
        }

        return $path;
    }

    /**
     * @throws RepositoryTakeoverException
     */
    protected function saveRepository(BackupRepositorySettings $settings, string $storedPassword): void
    {
        try {
            $this->backupRepository->createBackupRepository($settings, $storedPassword, PasswordSource::ENCRYPTED);
        } catch (\Throwable $e) {
            $this->throwSaveFailed($settings->getBackupRepositoryId(), $e);
        }
    }

    /**
     * Fixed message on purpose: neither the password nor raw database text may reach the response.
     * Only id and exception class/message are logged, never arguments.
     *
     * @throws RepositoryTakeoverException
     */
    protected function throwSaveFailed(string $backupRepositoryId, \Throwable $e): never
    {
        $this->logger->error('Repository takeover could not be saved', [
            'backupRepositoryId' => $backupRepositoryId,
            'exceptionClass' => $e::class,
            'exception' => $e->getMessage(),
        ]);

        throw new RepositoryTakeoverException(
            RepositoryTakeoverException::SAVE_FAILED,
            'The repository could not be saved',
            [],
            $e
        );
    }

    /**
     * @throws RepositoryTakeoverException
     */
    protected function assertUsableRepositoryFolder(string $path): void
    {
        if (!is_dir($path) || !is_readable($path)) {
            throw new RepositoryTakeoverException(RepositoryTakeoverException::PATH_NOT_FOUND, 'Repository path does not exist or is not readable');
        }
        if (!is_file($path . '/config')) {
            throw new RepositoryTakeoverException(RepositoryTakeoverException::NOT_A_REPOSITORY, 'The folder is not a restic repository');
        }

        $candidates = [$path];
        foreach (self::REQUIRED_WRITABLE_DIRECTORIES as $directory) {
            if (is_dir($path . '/' . $directory)) {
                $candidates[] = $path . '/' . $directory;
            }
        }
        foreach (glob($path . '/data/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $subDirectory) {
            $candidates[] = $subDirectory;
        }
        foreach ($candidates as $candidate) {
            if (!is_writable($candidate)) {
                throw new RepositoryTakeoverException(
                    RepositoryTakeoverException::NOT_WRITABLE,
                    'The repository is not writable for the web server user',
                    ['user' => $this->getProcessUserName(), 'path' => $candidate]
                );
            }
        }
    }

    protected function getProcessUserName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        return get_current_user();
    }

    /**
     * @throws RepositoryTakeoverException
     */
    protected function assertNotYetManaged(string $path, string $name, string $backupRepositoryId): void
    {
        try {
            $existing = $this->backupRepository->getBackupRepositoryByPath($path);
        } catch (\Throwable $e) {
            $this->throwSaveFailed($backupRepositoryId, $e);
        }
        if ($existing !== null) {
            throw new RepositoryTakeoverException(
                RepositoryTakeoverException::DUPLICATE,
                'This repository is already managed',
                ['name' => $name]
            );
        }
    }

    /**
     * @throws RepositoryTakeoverException
     */
    protected function prepareStoredPassword(string $repositoryPassword): string
    {
        try {
            return $this->backupRepository->prepareStoredPassword($repositoryPassword, PasswordSource::ENCRYPTED);
        } catch (\Throwable $e) {
            throw new RepositoryTakeoverException(
                RepositoryTakeoverException::PASSWORD_NOT_STORABLE,
                'The repository password could not be stored',
                [],
                $e
            );
        }
    }

    /**
     * @return array<int, mixed>
     * @throws RepositoryTakeoverException
     */
    protected function readSnapshots(string $path, string $repositoryPassword): array
    {
        try {
            return $this->manageRepository->readSnapshots($path, $repositoryPassword);
        } catch (\Throwable $e) {
            throw new RepositoryTakeoverException(
                RepositoryTakeoverException::ACCESS_FAILED,
                'Access to the repository failed',
                ['detail' => $this->createAccessDetail($e)],
                $e
            );
        }
    }

    /**
     * Short restic error text for the admin. For a failed process only its error output is used
     * (never the command line or working directory, which a ProcessFailedException message contains).
     */
    protected function createAccessDetail(\Throwable $e): string
    {
        $text = $e instanceof ProcessFailedException
            ? $e->getProcess()->getErrorOutput()
            : $e->getMessage();

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
        $detail = trim(mb_substr(implode(' ', array_slice($lines, 0, 3)), 0, self::MAX_DETAIL_LENGTH));

        return $detail !== '' ? $detail : 'check password and file permissions';
    }

    /**
     * @param array<int, mixed> $snapshots
     */
    protected function createSettings(
        string $backupRepositoryId,
        string $name,
        string $path,
        string $repositoryPassword,
        array $snapshots
    ): BackupRepositorySettings
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId($backupRepositoryId);
        $settings->setActive(false);
        $settings->setName($name);
        $settings->setBackupType(BackupTypes::NONE_DATABASE->value);
        $settings->setRepositoryPath($path);
        $settings->setRepositoryPassword($repositoryPassword);
        $settings->setRestorePath('');
        $settings->setDbDumpPath(null);
        $settings->setForgetDaily(0);
        $settings->setForgetWeekly(0);
        $settings->setForgetMonthly(0);
        $settings->setForgetYearly(0);
        $settings->setPreBackupCommandType('none');
        $settings->setPreBackupCommand('');
        $settings->setPreBackupCommandArguments('');
        $settings->setPreBackupFailOnError(true);
        $settings->setPostBackupCommandType('none');
        $settings->setPostBackupCommand('');
        $settings->setPostBackupCommandArguments('');
        $settings->setPostBackupFailOnError(true);
        $settings->setBackupPaths([]);
        $settings->setDatabaseConnectionId(null);

        $hostName = $this->findLatestHostName($snapshots);
        if ($hostName !== null) {
            $settings->setHostName($hostName);
        }

        return $settings;
    }

    /**
     * Hostname of the newest snapshot by real point in time (not by string order). An empty or
     * overlong hostname of that snapshot yields null (plugin default), older hosts are not used.
     *
     * @param array<int, mixed> $snapshots
     */
    protected function findLatestHostName(array $snapshots): ?string
    {
        $latestTime = null;
        $latest = null;
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot) || !is_string($snapshot['time'] ?? null)) {
                continue;
            }
            $time = $this->pluginHelper->createDateTimeFromString($snapshot['time']);
            if ($time === null) {
                continue;
            }
            if ($latestTime === null || $time > $latestTime) {
                $latestTime = $time;
                $latest = $snapshot;
            }
        }

        $hostName = is_array($latest) && is_string($latest['hostname'] ?? null) ? trim($latest['hostname']) : '';
        if ($hostName === '' || mb_strlen($hostName) > self::MAX_HOSTNAME_LENGTH) {
            return null;
        }

        return $hostName;
    }

    /**
     * @param array<int, mixed> $snapshots
     * @throws RepositoryTakeoverException
     */
    protected function importSnapshots(string $backupRepositoryId, array $snapshots): int
    {
        try {
            return $this->backupFileSnapshotsRepository->createNewSnapshots($backupRepositoryId, $snapshots);
        } catch (\Throwable $e) {
            $this->rollbackRecord($backupRepositoryId);
            throw new RepositoryTakeoverException(
                RepositoryTakeoverException::IMPORT_FAILED,
                'The snapshots could not be imported',
                [],
                $e
            );
        }
    }

    /**
     * Snapshot rows first (the foreign key is NO ACTION), then the record. A failing cleanup
     * is only logged: the caller must still get IMPORT_FAILED with the import error as previous.
     */
    protected function rollbackRecord(string $backupRepositoryId): void
    {
        try {
            $this->backupFileSnapshotsRepository->removeOldSnapshots($backupRepositoryId);
            $this->backupRepository->removeBackupRepository($backupRepositoryId);
        } catch (\Throwable $cleanupException) {
            $this->logger->error('Rollback after failed repository takeover import failed', [
                'backupRepositoryId' => $backupRepositoryId,
                'exception' => $cleanupException->getMessage(),
            ]);
        }
    }

    protected function dispatchCheck(string $backupRepositoryId): void
    {
        try {
            $this->messageBus->dispatch(new TakeoverRepositoryCheckMessage($backupRepositoryId));
        } catch (\Throwable $e) {
            $this->logger->error('Check after repository takeover could not be dispatched', [
                'backupRepositoryId' => $backupRepositoryId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
