<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

use MuckiFacilityPlugin\Controller\BackupController;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use Shopware\Core\Framework\Api\Context\AdminApiSource;

/**
 * S-02: repositories with configured pre-/post-backup commands execute shell code on
 * the worker — starting their backup requires the dedicated high-risk privilege.
 */
final class BackupControllerTest extends TestCase
{
    public function testBackupOfRepositoryWithCommandsRequiresExecuteCommandsPrivilege(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('hasBackupCommands')->willReturn(true);

        $controller = new BackupController($messageBus, $backupRepositoryService);

        $response = $controller->process(
            new RequestDataBag(['id' => Uuid::randomHex()]),
            $this->context(false)
        );

        self::assertSame(403, $response->getStatusCode());
        $errors = json_decode((string) $response->getContent(), true)['errors'];
        self::assertSame('MUWA_MISSING_PRIVILEGE', $errors[0]['code']);
        self::assertSame('Missing privilege muwa_backup_repository:execute_commands', $errors[0]['detail']);
    }

    public function testBackupOfRepositoryWithCommandsStartsWithPrivilege(): void
    {
        $repositoryId = Uuid::randomHex();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(fn ($message) => $message instanceof CreateBackupMessage))
            ->willReturnCallback(static fn () => new \Symfony\Component\Messenger\Envelope(new \stdClass()));

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('hasBackupCommands')->willReturn(true);

        $controller = new BackupController($messageBus, $backupRepositoryService);

        $response = $controller->process(
            new RequestDataBag(['id' => $repositoryId]),
            $this->context(true)
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testBackupWithoutCommandsNeedsNoExtraPrivilege(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturn(new \Symfony\Component\Messenger\Envelope(new \stdClass()));

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('hasBackupCommands')->willReturn(false);

        $controller = new BackupController($messageBus, $backupRepositoryService);

        $response = $controller->process(
            new RequestDataBag(['id' => Uuid::randomHex()]),
            $this->context(false)
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testBackupOfInactiveRepositoryIsRejected(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('isBackupRepositoryInactive')->willReturn(true);

        $controller = new BackupController($messageBus, $backupRepositoryService);

        $response = $controller->process(
            new RequestDataBag(['id' => Uuid::randomHex()]),
            $this->context(true)
        );

        self::assertSame(400, $response->getStatusCode());
        $errors = json_decode((string) $response->getContent(), true)['errors'];
        self::assertSame('MUWA_REPOSITORY_INACTIVE', $errors[0]['code']);
    }

    private function context(bool $allowExecuteCommands): Context
    {
        $source = new AdminApiSource(Uuid::randomHex());
        $source->setPermissions(array_merge(
            ['muwa_backup_repository:backup'],
            $allowExecuteCommands ? ['muwa_backup_repository:execute_commands'] : []
        ));

        return new Context($source);
    }
}
