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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use MuckiFacilityPlugin\Core\CheckStatus;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Events\AfterBackupCheckedEvent;
use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiFacilityPlugin\Events\AfterDatabaseDumpCreatedEvent;
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BackupFinishedEvent;
use MuckiFacilityPlugin\Events\BeforeDatabaseDumpCreatedEvent;
use MuckiFacilityPlugin\Events\DatabaseDumpFailedEvent;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Backup\Database\DumpFailureAwareInterface;
use MuckiFacilityPlugin\Core\ConfigPath;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\MessageQueue\Message\MirrorRepositoryMessage;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\RepositoryStats;
use MuckiFacilityPlugin\Services\Mirror\ErrorSanitizer;
use MuckiRestic\Entity\Result\ResultEntity;

/**
 *
 */
class Backup
{
    /**
     * @var array<mixed>
     */
    protected array $allResults = [];
    /**
     * @var \Exception
     */
    protected \Exception $backupException;
    /**
     * @var BackupInterface
     */
    protected BackupInterface $backup;
    /**
     * Sanitized error of the last failed checkBackup() call, null if it did not fail
     */
    protected ?string $checkFailure = null;
    public const CHECK_FAILED_PREFIX = 'check failed: ';
    public const CHECK_FAILED_MAX_LENGTH = 200;

    /**
     * Status of the extra check entry saved when the database dump failed but the run went on
     * (default connection without failOnDumpError). Never matches CheckStatus::isHealthy().
     */
    protected ?string $dumpFailure = null;
    public const DUMP_FAILED_PREFIX = 'database dump failed: ';

    /**
     * @param LoggerInterface $logger
     * @param BackupRunnerFactory $backupRunnerFactory
     * @param BackupRepository $backupRepository
     * @param BackupRepositoryChecks $backupRepositoryChecks
     * @param SettingsInterface $pluginSettings
     * @param Helper $pluginHelper
     * @param ManageRepository $manageService
     * @param CliOutput $servicesCliOutput
     * @param RepositoryStats $repositoryStats
     * @param EventDispatcherInterface $eventDispatcher
     * @param MessageBusInterface $messageBus
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRunnerFactory $backupRunnerFactory,
        protected BackupRepository $backupRepository,
        protected BackupRepositoryChecks $backupRepositoryChecks,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
        protected ManageService $manageService,
        protected ServicesCliOutput $servicesCliOutput,
        protected RepositoryStats $repositoryStats,
        protected EventDispatcherInterface $eventDispatcher,
        protected MessageBusInterface $messageBus
    )
    {
        $this->backupException = new \Exception();
    }

    /**
     * @return BackupInterface
     */
    public function getBackup(): BackupInterface
    {
        return $this->backup;
    }

    /**
     * @param BackupInterface $backup
     * @return void
     */
    public function setBackup(BackupInterface $backup): void
    {
        $this->backup = $backup;
    }

    /**
     * @return array<ResultEntity>
     */
    public function getAllResults(): array
    {
        return $this->allResults;
    }

    /**
     * @return \Exception
     */
    public function getBackupException(): \Exception
    {
        return $this->backupException;
    }

    /**
     * @param \Exception $backupException
     * @return void
     */
    public function setBackupException(\Exception $backupException): void
    {
        $this->backupException = $backupException;
    }

    /**
     * @param array<ResultEntity> $allResults
     * @return void
     */
    public function addAllResult(array $allResults): void
    {
        $this->allResults = array_merge($this->allResults, $allResults);
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @param bool $isJsonOutput
     * @return void
     */
    public function createBackup(BackupRepositorySettings $createBackup, bool $isJsonOutput=true): void
    {
        $this->resetRunState();

        //the backup type and paths of $createBackup are overwritten during the run
        $backupRepositoryId = $createBackup->getBackupRepositoryId();
        $startedAt = hrtime(true);
        $statsId = null;

        $primaryError = null;
        $finishedError = null;
        $durationError = null;
        $coreSucceeded = false;

        try {
            $this->eventDispatcher->dispatch(
                new BeforeBackupCreatedEvent($createBackup),
                BeforeBackupCreatedEvent::NAME
            );

            $cachePaths = $createBackup->getBackupPaths();

            //Backup database
            if($createBackup->getBackupType() !== BackupTypes::NONE_DATABASE->value) {
                $this->runDatabaseBackup($createBackup, $isJsonOutput);
            }

            //Backup files
            //$createBackup->getBackupPaths() has been overwritten by runDatabaseBackup(),
            //so the originally configured file paths have to be checked instead.
            if(!empty($cachePaths)) {
                $this->runFilesBackup($createBackup, $cachePaths, $isJsonOutput);
            }

            $checkId = $this->createCheckItem($createBackup);
            $this->dispatchMirror($backupRepositoryId, $checkId);
            $this->manageService->saveSnapshots($backupRepositoryId);
            $statsId = $this->repositoryStats->collectAndSaveForBackup($backupRepositoryId);

            $this->eventDispatcher->dispatch(
                new AfterBackupCreatedEvent($createBackup, $this->getAllResults()),
                AfterBackupCreatedEvent::NAME
            );
            $coreSucceeded = true;
        } catch (\Throwable $exception) {
            $primaryError = $exception;
        }

        try {
            $this->eventDispatcher->dispatch(
                new BackupFinishedEvent(
                    $createBackup,
                    $this->getAllResults(),
                    $primaryError,
                    $coreSucceeded
                ),
                BackupFinishedEvent::NAME
            );
        } catch (\Throwable $exception) {
            $finishedError = $exception;
        }

        try {
            $this->repositoryStats->saveBackupDuration(
                $backupRepositoryId,
                $statsId,
                $this->getElapsedSeconds($startedAt)
            );
        } catch (\Throwable $exception) {
            $durationError = $exception;
        }

        $errorToThrow = $primaryError ?? $finishedError ?? $durationError;
        if ($finishedError !== null && $finishedError !== $errorToThrow) {
            $this->logSuppressedLifecycleError('Finished lifecycle', $finishedError);
        }
        if ($durationError !== null && $durationError !== $errorToThrow) {
            $this->logSuppressedLifecycleError('Backup duration persistence', $durationError);
        }

        if ($errorToThrow !== null) {
            throw $errorToThrow;
        }
    }

    private function resetRunState(): void
    {
        $this->allResults = [];
        $this->checkFailure = null;
        $this->dumpFailure = null;
    }

    private function logSuppressedLifecycleError(string $stage, \Throwable $error): void
    {
        try {
            $this->logger->error(
                $stage.' failed after a higher-priority backup error: '.$error->getMessage(),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        } catch (\Throwable) {
            // Logging must never replace the lifecycle error selected for rethrow.
        }
    }

    /**
     * @param int|float $startedAt result of hrtime(true)
     */
    protected function getElapsedSeconds(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000_000);
    }

    /**
     * Queues the S3 mirror run when the repository has a mirror connection.
     * Must never break the backup.
     *
     * @param string $backupRepositoryId
     * @param string|null $checkId
     * @return void
     */
    protected function dispatchMirror(string $backupRepositoryId, ?string $checkId): void
    {
        try {
            $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
            if($backupRepository === null || empty($backupRepository->getMirrorS3ConnectionId())) {
                return;
            }
            $this->messageBus->dispatch(new MirrorRepositoryMessage($backupRepositoryId, $checkId));
        } catch (\Throwable $e) {
            $this->logger->error(
                'Mirror could not be queued. BackupRepositoryId: '.$backupRepositoryId.', '.$e->getMessage(),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @return void
     */
    public function createDump(BackupRepositorySettings $createBackup): void
    {
        $this->startBackupRunner($createBackup, false);
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @param bool $isJsonOutput
     * @return void
     */
    public function runDatabaseBackup(BackupRepositorySettings $createBackup, bool $isJsonOutput=true): void
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('run backup database...');
        }

        $dbDumpPath = $this->pluginSettings->getBackupPath(
            false,
            $createBackup->getDbDumpPath(),
            $createBackup->hasRepositoryPath() ? $createBackup->getRepositoryPath() : null,
            $createBackup->hasRestorePath() ? $createBackup->getRestorePath() : null
        );
        if(!$this->pluginHelper->deleteDirectory($dbDumpPath)) {
            $this->logger->error(
                'Database dump path could not be cleared before the dump, stale files may be included: '.$dbDumpPath,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
        }

        $backupPath = new BackupPathEntity();
        $backupPath->setBackupPath($dbDumpPath);
        $backupPath->setPosition(0);
        $backupPath->setCompress($this->pluginSettings->isCompressDbBackupEnabled());
        $backupPath->setIsDefault(false);

        $createBackup->setBackupPaths(array($backupPath));

        // finally: an aborted run (failed restic backup, own connection, failOnDumpError) must not leave
        // a plain text dump with customer data on disk until the next run
        try {
            //create database dump
            $this->startBackupRunner($createBackup, $isJsonOutput);

            //write dump into backup repository
            $createBackup->setBackupType(BackupTypes::FILES->value);
            $this->startBackupRunner($createBackup, $isJsonOutput);
        } finally {
            if(!$this->pluginHelper->deleteDirectory($dbDumpPath)) {
                $this->logger->error(
                    'Database dump path could not be cleared after the dump, stale files may end up in the next backup: '.$dbDumpPath,
                    PluginDefaults::DEFAULT_LOGGER_CONFIG
                );
            }
        }
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @param array<mixed> $cachePaths
     * @param bool $isJsonOutput
     * @return void
     */
    public function runFilesBackup(BackupRepositorySettings $createBackup, array $cachePaths, bool $isJsonOutput=true): void
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('run backup files...');
        }

        $createBackup->setBackupType(BackupTypes::FILES->value);
        $createBackup->setBackupPaths($cachePaths);

        $this->startBackupRunner($createBackup, $isJsonOutput);
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @return void
     */
    public function checkBackup(BackupRepositorySettings $createBackup): void
    {
        $this->checkFailure = null;
        $createBackup = clone $createBackup;
        $createBackup->setBackupType(BackupTypes::FILES->value);
        try {
            $backupRunner = $this->backupRunnerFactory->createBackupRunner($createBackup);
            $backupRunner->checkBackupData();

            $this->addAllResult($backupRunner->getBackupResults());
            $this->setBackup($backupRunner);

        } catch (\Exception $e) {
            $this->checkFailure = $this->buildCheckFailedStatus($e, $createBackup);
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }

    /**
     * Status text stored as check result when `restic check` itself failed (e.g. non-zero exit).
     * Never matches CheckStatus::isHealthy(), so the dashboard shows red and the mirror is skipped.
     */
    protected function buildCheckFailedStatus(\Exception $exception, BackupRepositorySettings $settings): string
    {
        $password = '';
        try {
            $password = $settings->getRepositoryPassword();
        } catch (\Error) {
            // password not initialized
        }
        $status = self::CHECK_FAILED_PREFIX . mb_substr(
            ErrorSanitizer::sanitize($exception, $password),
            0,
            self::CHECK_FAILED_MAX_LENGTH
        );

        return CheckStatus::isHealthy($status) ? rtrim(self::CHECK_FAILED_PREFIX, ': ') : $status;
    }

    public function getCheckFailure(): ?string
    {
        return $this->checkFailure;
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @param bool $isJsonOutput
     * @return void
     */
    public function startBackupRunner(BackupRepositorySettings $createBackup, bool $isJsonOutput): void
    {
        $isDatabaseDump = in_array(
            $createBackup->getBackupType(),
            [
                BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value,
                BackupTypes::COMPLETE_DATABASE_SEPARATE_FILES->value,
            ],
            true
        );

        try {
            if($isDatabaseDump) {
                $this->eventDispatcher->dispatch(
                    new BeforeDatabaseDumpCreatedEvent($createBackup),
                    BeforeDatabaseDumpCreatedEvent::NAME
                );
            }

            $backupRunner = $this->backupRunnerFactory->createBackupRunner($createBackup);
            $backupRunner->createBackupData($isJsonOutput);

            $backupResults = $backupRunner->getBackupResults();
            $this->addAllResult($backupResults);
            $this->setBackup($backupRunner);

            $dumpFailures = $backupRunner instanceof DumpFailureAwareInterface ? $backupRunner->getDumpFailures() : [];
            if($isDatabaseDump && $dumpFailures !== []) {
                $this->handleDumpFailures($createBackup, $dumpFailures);
            } elseif($isDatabaseDump) {
                $this->eventDispatcher->dispatch(
                    new AfterDatabaseDumpCreatedEvent($createBackup, $backupResults),
                    AfterDatabaseDumpCreatedEvent::NAME
                );
            }

        } catch (\Exception $e) {

            $this->setBackupException($e);
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw $e;
        }
    }

    /**
     * Dump errors the runner only logged (default connection). Makes them visible instead of
     * silently storing a backup without database: the event is dispatched, the extra red check entry
     * is saved by createCheckItem(), and with failOnDumpError the whole run is aborted.
     *
     * @param array<int, string> $dumpFailures
     * @throws DatabaseBackupFailedException with failOnDumpError
     */
    protected function handleDumpFailures(BackupRepositorySettings $createBackup, array $dumpFailures): void
    {
        $abort = $this->pluginSettings->isFailOnDumpErrorEnabled();
        $this->dumpFailure = $this->buildDumpFailedStatus($dumpFailures);

        try {
            $this->eventDispatcher->dispatch(
                new DatabaseDumpFailedEvent($createBackup, $dumpFailures, $abort, Context::createDefaultContext()),
                DatabaseDumpFailedEvent::EVENT_NAME
            );
        } catch (\Throwable $e) {
            // a failing flow or listener must not change the outcome of the backup run
            $this->logger->error('DatabaseDumpFailedEvent listener failed: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        if($abort) {
            throw new DatabaseBackupFailedException('Database backup failed, backup aborted (failOnDumpError): '.$dumpFailures[0]);
        }
    }

    /**
     * @param array<int, string> $dumpFailures password-masked messages from the dumper
     */
    protected function buildDumpFailedStatus(array $dumpFailures): string
    {
        $count = count($dumpFailures);
        $status = self::DUMP_FAILED_PREFIX
            .($count > 1 ? '('.$count.' dumps) ' : '')
            .mb_substr($dumpFailures[0], 0, self::CHECK_FAILED_MAX_LENGTH);

        return CheckStatus::isHealthy($status) ? rtrim(self::DUMP_FAILED_PREFIX, ': ') : $status;
    }

    /**
     * Status text of the failed database dump of the last createBackup() run, null when the dump
     * succeeded or was not part of the run.
     */
    public function getDumpFailure(): ?string
    {
        return $this->dumpFailure;
    }

    /**
     * @param BackupRepositorySettings $createBackup
     * @return string|null id of the last saved check, null if none was saved
     */
    public function createCheckItem(BackupRepositorySettings $createBackup): ?string
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('Check backup...');
        }
        $this->checkBackup($createBackup);

        $lastCheckResult = null;
        $lastCheckId = null;
        if($this->checkFailure !== null) {
            //a failed check must replace the last (maybe green) check, otherwise the mirror gate and
            //the dashboard would still see the old healthy result
            $lastCheckResult = $this->checkFailure;
            $lastCheckId = $this->backupRepositoryChecks->saveNewCheck(
                $createBackup->getBackupRepositoryId(),
                $lastCheckResult
            );
        }
        /** @var ResultEntity $result */
        foreach ($this->checkFailure === null ? $this->getAllResults() : [] as $result) {

            $checkResults = $this->pluginHelper->getCheckResults($result->getOutput());
            $checkResult = end($checkResults);
            if(!is_string($checkResult)) {
                continue;
            }
            $lastCheckId = $this->backupRepositoryChecks->saveNewCheck($createBackup->getBackupRepositoryId(), $checkResult);
            $lastCheckResult = $checkResult;
        }

        $this->saveDumpFailureCheck($createBackup);

        $backupRepository = $this->backupRepository->getBackupRepositoryById(
            $createBackup->getBackupRepositoryId()
        );
        if($backupRepository !== null && is_string($lastCheckResult)) {
            $this->eventDispatcher->dispatch(
                new AfterBackupCheckedEvent($backupRepository, $lastCheckResult),
                AfterBackupCheckedEvent::NAME
            );
        }

        return $lastCheckId;
    }

    /**
     * Extra red entry after the restic check, so dashboard and list show the run as failed. Its id is
     * not returned: the repository itself is fine, the mirror of this run keeps the restic check id.
     * Saved 1 ms later, otherwise both entries could share the timestamp and the green one could
     * stay the latest check.
     */
    protected function saveDumpFailureCheck(BackupRepositorySettings $createBackup): void
    {
        if($this->dumpFailure === null) {
            return;
        }

        try {
            $this->backupRepositoryChecks->saveNewCheck(
                $createBackup->getBackupRepositoryId(),
                $this->dumpFailure,
                (new \DateTimeImmutable())->modify('+1 millisecond')
            );
        } catch (\Throwable $e) {
            $this->logger->error('Database dump failure could not be saved as check: '.$e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }

    /**
     * @param array<mixed> $backupPaths
     * @return array<BackupPathEntity>
     */
    public function prepareBackupPaths(array $backupPaths): array
    {
        $preparedBackupPaths = [];
        foreach ($backupPaths as $backupPath) {

            $backupPathEntity = new BackupPathEntity();
            $backupPathEntity->setId($backupPath['id']);
            $backupPathEntity->setBackupPath($backupPath['backupPath']);
            $backupPathEntity->setCompress($backupPath['compress']);
            $backupPathEntity->setPosition($backupPath['position']);

            $preparedBackupPaths[] = $backupPathEntity;
        }

        return $preparedBackupPaths;
    }

    /**
     * @param string $backupRepositoryId
     * @return BackupRepositorySettings
     */
    public function prepareCreateBackup(string $backupRepositoryId): BackupRepositorySettings
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('Prepare backup');
        }

        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        $createBackup = new BackupRepositorySettings();

        if($backupRepository) {

            $createBackup->setBackupRepositoryId($backupRepositoryId);
            $createBackup->setName($backupRepository->getName());
            $createBackup->setBackupType($backupRepository->getType());
            $createBackup->setBackupPaths($this->prepareBackupPaths($backupRepository->getBackupPaths()));
            $createBackup->setRepositoryPath($backupRepository->getRepositoryPath());
            $createBackup->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $createBackup->setHostName($backupRepository->getHostname());
            $createBackup->setDbDumpPath($backupRepository->getDbDumpPath());
            $createBackup->setPreBackupCommandType($backupRepository->getPreBackupCommandType());
            $createBackup->setPreBackupCommand($backupRepository->getPreBackupCommand());
            $createBackup->setPreBackupCommandArguments($backupRepository->getPreBackupCommandArguments());
            $createBackup->setPreBackupFailOnError($backupRepository->isPreBackupFailOnError());
            $createBackup->setPostBackupCommandType($backupRepository->getPostBackupCommandType());
            $createBackup->setPostBackupCommand($backupRepository->getPostBackupCommand());
            $createBackup->setPostBackupCommandArguments($backupRepository->getPostBackupCommandArguments());
            $createBackup->setPostBackupFailOnError($backupRepository->isPostBackupFailOnError());
            $createBackup->setDatabaseConnectionId($backupRepository->getDatabaseConnectionId());
        }

        return $createBackup;
    }

    /**
     * @param string $backupRepositoryId
     * @return BackupRepositorySettings
     */
    public function prepareCheckBackup(string $backupRepositoryId): BackupRepositorySettings
    {
        if($this->servicesCliOutput->isCli()) {
            $this->servicesCliOutput->printCliOutputNewline('Prepare checkup');
        }

        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        $createBackup = new BackupRepositorySettings();

        if($backupRepository) {

            $createBackup->setBackupType(BackupTypes::FILES->value);
            $createBackup->setRepositoryPath($backupRepository->getRepositoryPath());
            $createBackup->setRepositoryPassword($backupRepository->getRepositoryPassword());
        } else {

            if($this->servicesCliOutput->isCli()) {

                $this->servicesCliOutput->printCliOutputNewline(
                    'Missing backup repository for id: '.$backupRepositoryId
                );
            }
        }

        return $createBackup;
    }

    /**
     * @param string $databaseName
     * @param bool $useSubFolder
     * @return string
     */
    public function prepareDbBackupFileName(string $databaseName, bool $useSubFolder=false): string
    {
        $backupPath = $this->pluginSettings->getBackupPath($useSubFolder);
        $backupDateTimeStamp = $this->pluginSettings->getDateTimestamp();
        $backupFileName = '';

        if($backupPath !== '') {
            $backupFileName = $backupPath.'/';
        }

        if($backupDateTimeStamp !== '') {
            $backupFileName .= $backupDateTimeStamp.'_';
        }

        if($databaseName !== '') {
            $backupFileName .= $databaseName;
        }

        if($this->pluginSettings->isCompressDbBackupEnabled()) {
            $backupFileName .= '.backup.sql.gz';
        } else {
            $backupFileName .= '.backup.sql';
        }

        return $backupFileName;
    }
}
