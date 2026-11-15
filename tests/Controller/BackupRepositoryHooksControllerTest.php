<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use MuckiFacilityPlugin\Controller\BackupRepositoryHooksController;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

/**
 * S-02: the hooks action route is the only write path for the pre-/post-backup
 * command fields (WriteProtected, system scope) and validates its payload.
 */
final class BackupRepositoryHooksControllerTest extends TestCase
{
    public function testUpdateValidatesAndWritesNormalizedPayload(): void
    {
        $repositoryId = Uuid::randomHex();

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(self::once())
            ->method('updateBackupRepositoryHooks')
            ->with($repositoryId, [
                'preBackupCommandType' => 'batch',
                'preBackupCommand' => '/shop/files/private/mucki-facility/batch/nightly.sh',
                'preBackupCommandArguments' => 'keep',
                'preBackupFailOnError' => false,
                'postBackupCommandType' => 'none',
                'postBackupCommand' => '',
                'postBackupCommandArguments' => '',
                'postBackupFailOnError' => true,
            ])
            ->willReturn(true);

        $controller = new BackupRepositoryHooksController($backupRepositoryService);

        $response = $controller->update($repositoryId, new RequestDataBag([
            'preBackupCommandType' => 'batch',
            'preBackupCommand' => '/shop/files/private/mucki-facility/batch/nightly.sh',
            'preBackupCommandArguments' => 'keep',
            'preBackupFailOnError' => false,
            'postBackupCommandType' => 'none',
        ]), Context::createDefaultContext());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testInvalidRepositoryIdIsRejected(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(self::never())->method('updateBackupRepositoryHooks');

        $controller = new BackupRepositoryHooksController($backupRepositoryService);

        $response = $controller->update('nope', new RequestDataBag([]), Context::createDefaultContext());

        self::assertSame(400, $response->getStatusCode());
        $errors = json_decode((string) $response->getContent(), true)['errors'];
        self::assertSame('MUWA_INVALID_ID', $errors[0]['code']);
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(self::never())->method('updateBackupRepositoryHooks');

        $controller = new BackupRepositoryHooksController($backupRepositoryService);

        $response = $controller->update(Uuid::randomHex(), new RequestDataBag([
            'preBackupCommandType' => 'bash',
        ]), Context::createDefaultContext());

        self::assertSame(400, $response->getStatusCode());
        $errors = json_decode((string) $response->getContent(), true)['errors'];
        self::assertSame('MUWA_INVALID_HOOK_CONFIGURATION', $errors[0]['code']);
    }

    public function testPhpCommandNamesAreRestricted(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(self::never())->method('updateBackupRepositoryHooks');

        $controller = new BackupRepositoryHooksController($backupRepositoryService);

        $response = $controller->update(Uuid::randomHex(), new RequestDataBag([
            'preBackupCommandType' => 'php',
            'preBackupCommand' => 'cache:clear; rm -rf /',
        ]), Context::createDefaultContext());

        self::assertSame(400, $response->getStatusCode());
        $errors = json_decode((string) $response->getContent(), true)['errors'];
        self::assertSame('MUWA_INVALID_HOOK_CONFIGURATION', $errors[0]['code']);
    }

    public function testUnknownRepositoryIsNotFound(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('updateBackupRepositoryHooks')->willReturn(false);

        $controller = new BackupRepositoryHooksController($backupRepositoryService);

        $this->expectException(NotFoundHttpException::class);

        $controller->update(Uuid::randomHex(), new RequestDataBag([]), Context::createDefaultContext());
    }
}
