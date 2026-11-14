<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\MessageQueue\Handler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\TakeoverRepositoryCheckMessage;
use MuckiFacilityPlugin\Services\AdminNotification;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\ManageRepository;
use MuckiFacilityPlugin\Services\RepositoryStats;

#[AsMessageHandler]
class TakeoverRepositoryCheckHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepository $backupRepository,
        protected ManageRepository $manageRepository,
        protected RepositoryStats $repositoryStats,
        protected AdminNotification $adminNotification
    )
    {}

    /**
     * Laeuft nach einer Repository-Uebernahme: Strukturpruefung, dann Statistik, dann Benachrichtigung.
     * Wirft nie, damit der Messenger die Nachricht nicht erneut zustellt.
     */
    public function __invoke(TakeoverRepositoryCheckMessage $message): void
    {
        $repositoryId = $message->getBackupRepositoryId();

        try {
            $repository = $this->backupRepository->getBackupRepositoryById($repositoryId);
        } catch (\Throwable $exception) {
            $this->logFailure('Repository could not be loaded', $repositoryId, $exception);
            return;
        }

        if ($repository === null) {
            $this->logger->error(
                'Takeover check skipped, repository not found. BackupRepositoryId: '.$repositoryId,
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            return;
        }

        $status = null;
        $firstError = null;

        try {
            $status = $this->manageRepository->checkRepositoryStructure($repositoryId);
        } catch (\Throwable $exception) {
            $firstError = $exception->getMessage();
            $this->logFailure('Takeover structure check failed', $repositoryId, $exception);
        }

        try {
            if ($this->repositoryStats->collectAndSave($repositoryId) === null) {
                $firstError ??= 'Repository statistics could not be collected';
            }
        } catch (\Throwable $exception) {
            $firstError ??= $exception->getMessage();
            $this->logFailure('Takeover stats collection failed', $repositoryId, $exception);
        }

        $context = Context::createDefaultContext();
        $name = (string) $repository->getName();

        try {
            if ($firstError === null) {
                $this->notify(
                    'success',
                    'muwa-backup-repository.notification.takeoverCheckSucceeded',
                    ['name' => $name, 'status' => (string) $status],
                    $context
                );
                return;
            }

            $this->notify(
                'error',
                'muwa-backup-repository.notification.takeoverCheckFailed',
                ['name' => $name, 'error' => $firstError],
                $context
            );
        } catch (\Throwable $exception) {
            $this->logFailure('Takeover notification failed', $repositoryId, $exception);
        }
    }

    private function logFailure(string $text, string $repositoryId, \Throwable $exception): void
    {
        $this->logger->error(
            $text.'. BackupRepositoryId: '.$repositoryId.'. Error: '.$exception->getMessage(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function notify(string $status, string $snippet, array $parameters, Context $context): void
    {
        $message = json_encode([
            'source' => 'mucki-facility',
            'snippet' => $snippet,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->adminNotification->createNotification([
            'id' => Uuid::randomHex(),
            'status' => $status,
            'message' => $message,
            'adminOnly' => true,
            'requiredPrivileges' => ['muwa_backup_repository:update'],
        ], $context);
    }
}
