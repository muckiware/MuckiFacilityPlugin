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

use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use MuckiFacilityPlugin\Core\CheckStatus;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Entity\MirrorResult;
use MuckiFacilityPlugin\Events\RepositoryUploadFailedEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadSucceededEvent;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryUploads;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;
use MuckiFacilityPlugin\Services\Mirror\ErrorSanitizer;
use MuckiFacilityPlugin\Services\Mirror\LocalRepositoryScanner;
use MuckiFacilityPlugin\Exception\InvalidMirrorTargetException;
use MuckiFacilityPlugin\Services\Mirror\MirrorKeyRules;
use MuckiFacilityPlugin\Services\Mirror\MirrorTargetValidator;
use MuckiFacilityPlugin\Services\Mirror\MirrorPlanner;
use MuckiFacilityPlugin\Services\Mirror\MirrorRun;
use MuckiFacilityPlugin\Services\Mirror\S3ClientFactory;

/**
 * Mirrors a local restic repository into an S3 bucket (upload missing files, then delete stale
 * managed keys). Never throws; every run that gets past target resolution and the mirror lock is
 * stored in the upload history and dispatched as Flow event.
 *
 * Deletion safety: any error before or during the upload phase ends the run as "failed" without a
 * single remote deletion; the local repository is re-checked right before deleting. Deletes only
 * happen when the remote config existed (and matched) at the start of the run, so the first run
 * into a target never deletes.
 */
class RepositoryMirror
{
    public const LOCK_PREFIX = 'mucki-facility.mirror.';
    public const LOCK_TTL = 3600.0;
    public const REFRESH_INTERVAL = 50;
    public const REFRESH_SECONDS = 600.0;
    public const MULTIPART_THRESHOLD = 67108864; // 64 MiB
    public const DELETE_BATCH_SIZE = 1000;
    public const MAX_REMOTE_CONFIG_SIZE = 1048576;

    /**
     * Seconds to wait between re-checks of a locked local repository before the upload (sum 110 s).
     */
    public const LOCK_WAIT_DELAYS = [5, 15, 30, 60];

    /**
     * No overall request timeout: a single multipart part or large PutObject may take long.
     * Stalls are caught instead: read_timeout (stream handler) and the curl low-speed limit below.
     */
    public const HTTP_OPTIONS = ['connect_timeout' => 10, 'timeout' => 0, 'read_timeout' => 300];

    /**
     * A transfer slower than this many bytes per second for STALL_SECONDS is aborted (curl handler).
     */
    public const STALL_MIN_BYTES_PER_SECOND = 1024;
    public const STALL_SECONDS = 300;

    /**
     * Trigger source of runs queued by a backup run (MirrorRepositoryHandler).
     */
    public const TRIGGER_BACKUP = 'backup';

    protected const NOTIFICATION_PRIVILEGE = 'muwa_backup_repository:backup';

    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepository $backupRepository,
        protected BackupRepositoryChecks $backupRepositoryChecks,
        protected BackupRepositoryUploads $backupRepositoryUploads,
        protected S3ConnectionRepository $s3ConnectionRepository,
        protected S3ClientFactory $s3ClientFactory,
        protected LocalRepositoryScanner $scanner,
        protected MirrorPlanner $planner,
        protected LockFactory $lockFactory,
        protected EventDispatcherInterface $eventDispatcher,
        protected AdminNotification $adminNotification,
        protected MirrorTargetValidator $mirrorTargetValidator
    ) {}

    /**
     * Never throws. An unexpected error outside the guarded run (e.g. while building the result)
     * is logged and ends with null, like the other "nothing recorded" cases.
     *
     * @return MirrorResult|null null when the repository is unknown, has no mirror target, the
     *                           mirror lock is held by another run, or the run aborted unexpectedly
     *                           (nothing stored, no event)
     */
    public function mirror(
        string $backupRepositoryId,
        string $triggerSource,
        ?string $checkId,
        bool $ignoreCheck = false
    ): ?MirrorResult {
        try {
            return $this->mirrorOnce($backupRepositoryId, $triggerSource, $checkId, $ignoreCheck);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'S3 mirror: run aborted unexpectedly, nothing recorded: ' . ErrorSanitizer::sanitize($exception),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );

            return null;
        }
    }

    protected function mirrorOnce(
        string $backupRepositoryId,
        string $triggerSource,
        ?string $checkId,
        bool $ignoreCheck
    ): ?MirrorResult {
        $target = $this->loadTarget($backupRepositoryId);
        if ($target === null) {
            return null;
        }
        $lock = $this->acquireLock($backupRepositoryId);
        if ($lock === null) {
            return null;
        }

        try {
            $run = new MirrorRun($target[0], $target[1], $triggerSource, $lock);
            $run->setLastRefreshAt($this->now());
            $result = $this->run($run, $checkId, $ignoreCheck);
            $this->publish($run, $result);

            return $result;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @return array{0: BackupRepositoryEntity, 1: S3ConnectionEntity}|null
     */
    protected function loadTarget(string $backupRepositoryId): ?array
    {
        try {
            $repository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
            $connectionId = (string) $repository?->getMirrorS3ConnectionId();
            if ($repository === null || $connectionId === '') {
                return null;
            }
            $connection = $this->s3ConnectionRepository->getById($connectionId);
            if ($connection === null) {
                $this->logger->warning(
                    'S3 mirror: configured S3 connection not found, mirror not started',
                    PluginDefaults::DEFAULT_LOGGER_CONFIG
                );

                return null;
            }

            return [$repository, $connection];
        } catch (\Throwable $exception) {
            $this->logger->error(
                'S3 mirror: loading the repository failed: ' . ErrorSanitizer::sanitize($exception),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );

            return null;
        }
    }

    protected function acquireLock(string $backupRepositoryId): ?LockInterface
    {
        try {
            $lock = $this->lockFactory->createLock(self::LOCK_PREFIX . $backupRepositoryId, self::LOCK_TTL);

            return $lock->acquire(false) ? $lock : null;
        } catch (\Throwable $exception) {
            $this->logger->error(
                'S3 mirror: acquiring the mirror lock failed: ' . ErrorSanitizer::sanitize($exception),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );

            return null;
        }
    }

    protected function releaseLock(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable $exception) {
            $this->logger->error(
                'S3 mirror: releasing the mirror lock failed: ' . ErrorSanitizer::sanitize($exception),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }
    }

    protected function run(MirrorRun $run, ?string $checkId, bool $ignoreCheck): MirrorResult
    {
        $secret = '';
        try {
            $repositoryId = $run->getRepository()->getId();
            if ($run->getTriggerSource() === self::TRIGGER_BACKUP && $checkId === null) {
                // the check of this backup run produced no result: never fall back to an older (green) check
                return $run->toResult(
                    MirrorResult::STATUS_SKIPPED,
                    'No check result from this backup run; mirror skipped.'
                );
            }
            if (!$ignoreCheck && !$this->isCheckHealthy($repositoryId, $checkId)) {
                return $run->toResult(
                    MirrorResult::STATUS_SKIPPED,
                    'Repository check is missing or not healthy; mirror skipped.'
                );
            }
            $failure = $this->checkPreconditions($run);
            if ($failure !== null) {
                return $run->toResult(MirrorResult::STATUS_FAILED, $failure);
            }
            $secret = $this->s3ConnectionRepository->resolveSecret($run->getConnection());
            $client = $this->s3ClientFactory->create($run->getConnection(), $secret, null, $this->httpOptions());

            return $this->synchronize($run, $client);
        } catch (\Throwable $exception) {
            $message = ErrorSanitizer::sanitize($exception, $secret, $this->repositoryPassword($run));

            return $run->toResult(MirrorResult::STATUS_FAILED, $message);
        }
    }

    /**
     * The resolved restic password is masked as well; the property may be uninitialized.
     */
    protected function repositoryPassword(MirrorRun $run): string
    {
        try {
            return $run->getRepository()->getRepositoryPassword();
        } catch (\Error) {
            return '';
        }
    }

    /**
     * Without a check id (CLI / manual runs only) the newest stored check of the repository is used.
     */
    protected function isCheckHealthy(string $backupRepositoryId, ?string $checkId): bool
    {
        $check = $checkId !== null
            ? $this->backupRepositoryChecks->getById($checkId)
            : $this->backupRepositoryChecks->getLatestChecksByRepositoryId($backupRepositoryId);

        return $check !== null
            && $check->getBackupRepositoryId() === $backupRepositoryId
            && CheckStatus::isHealthy($check->getCheckStatus());
    }

    /**
     * Local safety brake (spec step 2): nothing remote is touched unless this returns null.
     */
    protected function checkPreconditions(MirrorRun $run): ?string
    {
        if ($run->getBucket() === '') {
            return 'No S3 bucket configured; nothing was changed remotely.';
        }
        if (!MirrorTargetValidator::isValidBucketName($run->getBucket())) {
            return 'Invalid S3 bucket name; nothing was changed remotely.';
        }
        $run->setPrefix(MirrorKeyRules::normalizePrefix($run->getRepository()->getMirrorS3Prefix()));

        try {
            $overlap = $this->mirrorTargetValidator->findOverlap(
                $run->getRepository()->getId(),
                (string) $run->getRepository()->getMirrorS3ConnectionId(),
                $run->getBucket(),
                $run->getPrefix()
            );
        } catch (InvalidMirrorTargetException $exception) {
            return $exception->getMessage() . '; nothing was changed remotely.';
        }
        if ($overlap !== null) {
            return sprintf(
                'Mirror target overlaps with the target of "%s"; nothing was changed remotely.',
                $overlap
            );
        }

        $path = $run->getPath();
        if ($path === '' || !$this->scanner->isResticRepository($path)) {
            return 'Local repository path is missing or not a restic repository (config and keys required); nothing was changed remotely.';
        }
        if (!$this->waitForLockRelease($path)) {
            return sprintf(
                'Local repository is still locked by a running restic operation after waiting %d seconds; nothing was changed remotely.',
                array_sum(self::LOCK_WAIT_DELAYS)
            );
        }

        return null;
    }

    /**
     * Short restic operations (snapshots, stats, check of a parallel run) hold their lock only for
     * seconds, so a locked repository is re-checked with a backoff before the run gives up.
     * Only used before the upload; the delete phase stays strict (deletePhaseBlocker()).
     */
    protected function waitForLockRelease(string $path): bool
    {
        foreach (self::LOCK_WAIT_DELAYS as $delay) {
            if (!$this->scanner->hasActiveLocks($path)) {
                return true;
            }
            $this->logger->info(
                sprintf('S3 mirror: local repository is locked, re-checking in %d seconds.', $delay),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            $this->pause($delay);
        }

        return !$this->scanner->hasActiveLocks($path);
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    protected function synchronize(MirrorRun $run, S3Client $client): MirrorResult
    {
        $local = $this->scanner->scan($run->getPath());
        $remote = $this->listRemote($client, $run);
        if (!$this->remoteConfigMatches($client, $run, $remote)) {
            return $run->toResult(
                MirrorResult::STATUS_FAILED,
                'A different repository exists at the target (remote config differs); nothing was changed remotely.'
            );
        }

        // checked before the upload: the upload phase writes config itself
        $remoteConfigExisted = array_key_exists('config', $remote);
        $plan = $this->planner->plan($local, $remote);
        $run->setFilesUnchanged($plan->getUnchangedCount());
        $this->uploadAll($client, $run, $plan->getUploads(), $local);

        if ($plan->getDeletes() === []) {
            return $run->toResult(MirrorResult::STATUS_SUCCEEDED, null);
        }
        if (!$remoteConfigExisted) {
            // first mirror into this target: the remote keys may belong to something else, never delete
            return $run->toResult(MirrorResult::STATUS_SUCCEEDED, sprintf(
                'First mirror into this target (no remote config at start); %d remote key(s) not in the local repository were not deleted, deletes are deferred to a later run.',
                count($plan->getDeletes())
            ));
        }
        $blocker = $this->deletePhaseBlocker($run, $plan->getDeletes(), $local);
        if ($blocker !== null) {
            return $run->toResult(MirrorResult::STATUS_FAILED, $blocker);
        }
        $this->deleteAll($client, $run, $plan->getDeletes());

        return $run->toResult(MirrorResult::STATUS_SUCCEEDED, null);
    }

    /**
     * @return array<string, int> relative key (prefix stripped) => size; keys outside the prefix are ignored
     */
    protected function listRemote(S3Client $client, MirrorRun $run): array
    {
        $prefix = $run->getPrefix();
        $remote = [];
        $pages = $client->getPaginator('ListObjectsV2', ['Bucket' => $run->getBucket(), 'Prefix' => $prefix]);
        foreach ($pages as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = (string) ($object['Key'] ?? '');
                if ($key === $prefix || !str_starts_with($key, $prefix)) {
                    continue;
                }
                $remote[substr($key, strlen($prefix))] = (int) ($object['Size'] ?? 0);
            }
        }

        return $remote;
    }

    /**
     * Spec step 2a: an existing remote config must be byte-identical to the local one.
     *
     * @param array<string, int> $remote
     */
    protected function remoteConfigMatches(S3Client $client, MirrorRun $run, array $remote): bool
    {
        if (!array_key_exists('config', $remote)) {
            return true; // first mirror into this target
        }
        if ($remote['config'] > self::MAX_REMOTE_CONFIG_SIZE) {
            return false;
        }
        $local = file_get_contents($run->getPath() . '/config');
        if ($local === false) {
            throw new \RuntimeException('Cannot read local repository config');
        }
        $object = $client->getObject(['Bucket' => $run->getBucket(), 'Key' => $run->getPrefix() . 'config']);

        return (string) $object['Body'] === $local;
    }

    /**
     * @param list<string> $uploads
     * @param array<string, int> $local
     */
    protected function uploadAll(S3Client $client, MirrorRun $run, array $uploads, array $local): void
    {
        foreach ($uploads as $key) {
            $size = $local[$key];
            $this->uploadFile($client, $run, $key, $size);
            $this->refreshLockIfDue($run, $run->recordUpload($size) % self::REFRESH_INTERVAL === 0);
        }
    }

    protected function uploadFile(S3Client $client, MirrorRun $run, string $key, int $size): void
    {
        $body = Utils::streamFor(Utils::tryFopen($run->getPath() . '/' . $key, 'rb'));
        $remoteKey = $run->getPrefix() . $key;
        try {
            if ($size >= $this->multipartThreshold()) {
                // ACL null: no x-amz-acl header (buckets with disabled ACLs may reject ACL headers).
                // @phpstan-ignore argument.type (SDK docblock says string, null is skipped by the serializer)
                (new ObjectUploader($client, $run->getBucket(), $remoteKey, $body, null, [
                    'mup_threshold' => $this->multipartThreshold(),
                    'before_upload' => fn () => $this->refreshLockIfDue($run, false),
                ]))->upload();

                return;
            }
            $client->putObject(['Bucket' => $run->getBucket(), 'Key' => $remoteKey, 'Body' => $body]);
        } finally {
            $body->close();
        }
    }

    protected function multipartThreshold(): int
    {
        return self::MULTIPART_THRESHOLD;
    }

    /**
     * Refreshes the mirror lock after REFRESH_INTERVAL files ($countDue) or REFRESH_SECONDS.
     */
    protected function refreshLockIfDue(MirrorRun $run, bool $countDue): void
    {
        $now = $this->now();
        if (!$countDue && $now - $run->getLastRefreshAt() < self::REFRESH_SECONDS) {
            return;
        }
        $run->getLock()->refresh();
        $run->setLastRefreshAt($now);
    }

    protected function now(): float
    {
        return microtime(true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpOptions(): array
    {
        $options = self::HTTP_OPTIONS;
        if (\defined('CURLOPT_LOW_SPEED_LIMIT') && \defined('CURLOPT_LOW_SPEED_TIME')) {
            $options['curl'] = [
                \CURLOPT_LOW_SPEED_LIMIT => self::STALL_MIN_BYTES_PER_SECOND,
                \CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
            ];
        }

        return $options;
    }

    /**
     * Second safety check right before deleting (spec step 2, repeated before step 6).
     *
     * @param list<string> $deletes
     * @param array<string, int> $local
     */
    protected function deletePhaseBlocker(MirrorRun $run, array $deletes, array $local): ?string
    {
        if ($this->hasDataDeletes($deletes) && !$this->hasDataFiles($local)) {
            return 'Local repository has no files under data/ (half-mounted?); remote deletions of data/ refused.';
        }
        $path = $run->getPath();
        if (!$this->scanner->isResticRepository($path)) {
            return 'Local repository disappeared before the delete phase; remote deletions skipped.';
        }
        if ($this->scanner->hasActiveLocks($path)) {
            return 'Local repository was locked before the delete phase; remote deletions skipped.';
        }
        $run->getLock()->refresh();

        return null;
    }

    /**
     * @param list<string> $deletes
     */
    protected function hasDataDeletes(array $deletes): bool
    {
        foreach ($deletes as $key) {
            if (str_starts_with($key, 'data/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $local
     */
    protected function hasDataFiles(array $local): bool
    {
        foreach (array_keys($local) as $key) {
            if (str_starts_with((string) $key, 'data/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $deletes
     */
    protected function deleteAll(S3Client $client, MirrorRun $run, array $deletes): void
    {
        foreach (array_chunk($deletes, self::DELETE_BATCH_SIZE) as $batch) {
            $response = $client->deleteObjects([
                'Bucket' => $run->getBucket(),
                'Delete' => [
                    'Objects' => array_map(static fn (string $key): array => ['Key' => $run->getPrefix() . $key], $batch),
                    'Quiet' => true,
                ],
            ]);
            $errors = $response['Errors'] ?? [];
            $run->recordDeleted(count($batch) - count($errors));
            if ($errors !== []) {
                throw new \RuntimeException(sprintf(
                    'DeleteObjects failed for %d object(s): %s: %s',
                    count($errors),
                    (string) ($errors[0]['Code'] ?? 'UnknownError'),
                    (string) ($errors[0]['Message'] ?? '')
                ));
            }
            $run->getLock()->refresh();
        }
    }

    protected function publish(MirrorRun $run, MirrorResult $result): void
    {
        $repository = $run->getRepository();
        $context = Context::createDefaultContext();
        $this->attempt('saving the upload history', fn () => $this->backupRepositoryUploads->save($repository->getId(), $result));
        $this->attempt('dispatching the event', fn () => $this->dispatchEvent($repository, $result, $context));
        if ($result->getStatus() === MirrorResult::STATUS_SUCCEEDED) {
            return;
        }

        $this->logger->warning(
            sprintf('S3 mirror %s: %s', $result->getStatus(), (string) $result->getMessage()),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
        $this->attempt('creating the admin notification', fn () => $this->notify($repository, $result, $context));
    }

    protected function dispatchEvent(BackupRepositoryEntity $repository, MirrorResult $result, Context $context): void
    {
        $event = $result->getStatus() === MirrorResult::STATUS_SUCCEEDED
            ? new RepositoryUploadSucceededEvent($repository->getId(), $repository->getName(), $result, $context)
            : new RepositoryUploadFailedEvent($repository->getId(), $repository->getName(), $result, $context);

        $this->eventDispatcher->dispatch($event, $event->getName());
    }

    protected function notify(BackupRepositoryEntity $repository, MirrorResult $result, Context $context): void
    {
        $snippet = $result->getStatus() === MirrorResult::STATUS_SKIPPED
            ? 'muwa-backup-repository.notification.mirrorSkipped'
            : 'muwa-backup-repository.notification.mirrorFailed';
        $message = json_encode([
            'source' => 'mucki-facility',
            'snippet' => $snippet,
            'parameters' => ['name' => $repository->getName(), 'message' => (string) $result->getMessage()],
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->adminNotification->createNotification([
            'id' => Uuid::randomHex(),
            'status' => 'error',
            'message' => $message,
            'adminOnly' => true,
            'requiredPrivileges' => [self::NOTIFICATION_PRIVILEGE],
        ], $context);
    }

    protected function attempt(string $what, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('S3 mirror: %s failed: %s', $what, ErrorSanitizer::sanitize($exception)),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }
    }
}
