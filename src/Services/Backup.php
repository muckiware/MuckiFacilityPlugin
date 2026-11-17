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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use MuckiFacilityPlugin\Core\CheckStatus;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Events\AfterBackupCheckedEvent;
use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiFacilityPlugin\Events\AfterDatabaseDumpCreatedEvent;
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BeforeDatabaseDumpCreatedEvent;
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
        $this->dispatchMirror($createBackup->getBackupRepositoryId(), $checkId);
        $this->manageService->saveSnapshots($createBackup->getBackupRepositoryId());
        $this->repositoryStats->collectAndSave($createBackup->getBackupRepositoryId());

        $this->eventDispatcher->dispatch(
            new AfterBackupCreatedEvent($createBackup, $this->getAllResults()),
            AfterBackupCreatedEvent::NAME
        );
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

        //create database dump
        $this->startBackupRunner($createBackup, $isJsonOutput);

        //write dump into backup repository
        $createBackup->setBackupType(BackupTypes::FILES->value);
        $this->startBackupRunner($createBackup, $isJsonOutput);

        if(!$this->pluginHelper->deleteDirectory($dbDumpPath)) {
            $this->logger->error(
                'Database dump path could not be cleared after the dump, stale files may end up in the next backup: '.$dbDumpPath,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
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

            if($isDatabaseDump) {
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
