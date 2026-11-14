<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

use MuckiFacilityPlugin\Controller\InitBackupRepositoryController;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Services\Content\DatabaseConnectionRepository;

/**
 * Only the database connection check: a non-existing connection id must fail before
 * `restic init` instead of ending in a foreign key error after the restic repository exists.
 */
class InitBackupRepositoryControllerTest extends TestCase
{
    public function testUnknownDatabaseConnectionIsRejectedBeforeResticInit(): void
    {
        $connectionId = Uuid::randomHex();

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(static::never())->method('prepareStoredPassword');
        $backupRepositoryService->expects(static::never())->method('initRepository');
        $backupRepositoryService->expects(static::never())->method('createBackupRepository');

        $databaseConnectionRepository = $this->createMock(DatabaseConnectionRepository::class);
        $databaseConnectionRepository->expects(static::once())
            ->method('getById')
            ->with($connectionId)
            ->willReturn(null);

        $controller = new InitBackupRepositoryController($backupRepositoryService, $databaseConnectionRepository);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database connection not found');

        $controller->initRepository($this->input(['databaseConnectionId' => $connectionId]), Context::createDefaultContext());
    }

    public function testExistingDatabaseConnectionPassesTheCheck(): void
    {
        $connectionId = Uuid::randomHex();

        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        // stop right after the input checks
        $backupRepositoryService->expects(static::once())
            ->method('prepareStoredPassword')
            ->willThrowException(new \RuntimeException('stop after input checks'));

        $databaseConnectionRepository = $this->createMock(DatabaseConnectionRepository::class);
        $databaseConnectionRepository->method('getById')->willReturn(new DatabaseConnectionEntity());

        $controller = new InitBackupRepositoryController($backupRepositoryService, $databaseConnectionRepository);

        $this->expectExceptionMessage('stop after input checks');

        $controller->initRepository($this->input(['databaseConnectionId' => $connectionId]), Context::createDefaultContext());
    }

    public function testDefaultConnectionNeedsNoLookup(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('prepareStoredPassword')
            ->willThrowException(new \RuntimeException('stop after input checks'));

        $databaseConnectionRepository = $this->createMock(DatabaseConnectionRepository::class);
        $databaseConnectionRepository->expects(static::never())->method('getById');

        $controller = new InitBackupRepositoryController($backupRepositoryService, $databaseConnectionRepository);

        $this->expectExceptionMessage('stop after input checks');

        $controller->initRepository($this->input(['databaseConnectionId' => null]), Context::createDefaultContext());
    }

    /**
     * S-02: a hook configuration in the init request requires the dedicated privilege.
     */
    public function testHookConfigurationRequiresItsOwnPrivilege(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(static::never())->method('prepareStoredPassword');

        $databaseConnectionRepository = $this->createMock(DatabaseConnectionRepository::class);

        $controller = new InitBackupRepositoryController($backupRepositoryService, $databaseConnectionRepository);

        // AdminApiSource without the privilege: createDefaultContext() is a system context
        // whose isAllowed() is always true and would not exercise the check.
        $source = new \Shopware\Core\Framework\Api\Context\AdminApiSource(Uuid::randomHex());
        $source->setPermissions([]);
        $response = $controller->initRepository($this->input([
            'preBackupCommandType' => 'batch',
            'preBackupCommand' => '/shop/files/private/mucki-facility/batch/nightly.sh',
        ]), new Context($source));

        static::assertSame(403, $response->getStatusCode());
        $errors = json_decode((string) $response->getContent(), true)['errors'];
        static::assertSame('MUWA_MISSING_PRIVILEGE', $errors[0]['code']);
    }

    /**
     * S-02: hook values are validated before anything expensive happens.
     */
    public function testInvalidHookConfigurationIsRejected(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->expects(static::never())->method('prepareStoredPassword');

        $databaseConnectionRepository = $this->createMock(DatabaseConnectionRepository::class);

        $controller = new InitBackupRepositoryController($backupRepositoryService, $databaseConnectionRepository);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid backup command configuration');

        $controller->initRepository($this->input([
            'preBackupCommandType' => 'bash',
        ]), Context::createDefaultContext());
    }

    /**
     * S-02: with the privilege, a hook configuration passes the check (and stops at the
     * mocked next step, as in the existing flow tests).
     */
    public function testHookConfigurationPassesWithPrivilege(): void
    {
        $backupRepositoryService = $this->createMock(BackupRepositoryService::class);
        $backupRepositoryService->method('prepareStoredPassword')
            ->willThrowException(new \RuntimeException('stop after input checks'));

        $databaseConnectionRepository = $this->createMock(DatabaseConnectionRepository::class);

        $controller = new InitBackupRepositoryController($backupRepositoryService, $databaseConnectionRepository);

        $this->expectExceptionMessage('stop after input checks');

        $controller->initRepository($this->input([
            'preBackupCommandType' => 'batch',
            'preBackupCommand' => '/shop/files/private/mucki-facility/batch/nightly.sh',
        ]), $this->contextWithExecuteCommandsPrivilege());
    }

    private function contextWithExecuteCommandsPrivilege(): Context
    {
        $source = new \Shopware\Core\Framework\Api\Context\AdminApiSource(Uuid::randomHex());
        $source->setPermissions(['muwa_backup_repository:execute_commands']);

        return new Context($source);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function input(array $override): RequestDataBag
    {
        return new RequestDataBag(array_merge([
            'id' => Uuid::randomHex(),
            'name' => 'Shop backup',
            'type' => 'completeDatabaseSingleFile',
            'repositoryPath' => '/var/backup/repo',
            'restorePath' => '/var/backup/restore',
            'repositoryPassword' => 'repo-pw',
            'repositoryRepeatPassword' => 'repo-pw',
        ], $override));
    }
}
