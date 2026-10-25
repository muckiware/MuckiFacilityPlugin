<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\PlatformRequest;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Controller\BackupController;
use MuckiFacilityPlugin\Controller\FilesystemController;
use MuckiFacilityPlugin\Controller\InitBackupRepositoryController;
use MuckiFacilityPlugin\Controller\ManageController;
use MuckiFacilityPlugin\Controller\RestoreSnapshotController;
use MuckiFacilityPlugin\Controller\ScheduleController;

/**
 * Jede API-Route des Plugins muss ein ACL-Recht mitbringen.
 *
 * `_routeScope: api` prueft nur, dass ueberhaupt authentifiziert wurde — ohne `_acl` darf jeder
 * Admin-Account und jede Integration die Route aufrufen, unabhaengig von der Rolle. Der Test
 * liest die Route-Attribute per Reflection und braucht deshalb keinen Shopware-Bootstrap.
 */
class RouteAclTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, array<int, string>}>
     */
    public static function routeAclProvider(): array
    {
        return [
            'backup process' => [
                BackupController::class,
                'process',
                ['muwa_backup_repository:backup'],
            ],
            'backup schedule' => [
                ScheduleController::class,
                'configure',
                ['muwa_backup_repository:update'],
            ],
            'repository init' => [
                InitBackupRepositoryController::class,
                'initRepository',
                ['muwa_backup_repository:create'],
            ],
            'restore process' => [
                RestoreSnapshotController::class,
                'process',
                ['muwa_backup_repository:restore'],
            ],
            'get snapshots' => [
                ManageController::class,
                'getSnapshots',
                ['muwa_backup_repository:read'],
            ],
            'remove snapshots' => [
                ManageController::class,
                'removeSnapshots',
                ['muwa_backup_repository:snapshot_delete'],
            ],
            'repository stats' => [
                ManageController::class,
                'getRepositoryStats',
                ['muwa_backup_repository:read'],
            ],
            'filesystem directories' => [
                FilesystemController::class,
                'listDirectories',
                ['muwa_backup_repository:update'],
            ],
            'filesystem files' => [
                FilesystemController::class,
                'listFiles',
                ['muwa_backup_repository:update'],
            ],
            'filesystem check dump path' => [
                FilesystemController::class,
                'checkDumpPath',
                ['muwa_backup_repository:update'],
            ],
        ];
    }

    /**
     * @param class-string $controllerClass
     * @param array<int, string> $expectedPrivileges
     *
     * @dataProvider routeAclProvider
     */
    public function testRouteRequiresAclPrivileges(
        string $controllerClass,
        string $methodName,
        array $expectedPrivileges
    ): void {
        $defaults = $this->getRouteDefaults($controllerClass, $methodName);

        self::assertArrayHasKey(
            PlatformRequest::ATTRIBUTE_ACL,
            $defaults,
            sprintf('%s::%s() hat keine ACL-Pruefung', $controllerClass, $methodName)
        );
        self::assertSame($expectedPrivileges, $defaults[PlatformRequest::ATTRIBUTE_ACL]);
    }

    /**
     * Faengt Routes ab, die spaeter ohne ACL dazukommen.
     */
    public function testEveryRouteInControllerNamespaceIsCovered(): void
    {
        $covered = [];
        foreach (self::routeAclProvider() as $case) {
            $covered[] = $case[0] . '::' . $case[1];
        }

        $uncovered = [];
        foreach (glob(__DIR__ . '/../../src/Controller/*.php') ?: [] as $controllerFile) {
            $controllerClass = 'MuckiFacilityPlugin\\Controller\\' . basename($controllerFile, '.php');

            $reflectionClass = new \ReflectionClass($controllerClass);
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(Route::class) === []) {
                    continue;
                }

                $identifier = $controllerClass . '::' . $method->getName();
                if (!in_array($identifier, $covered, true)) {
                    $uncovered[] = $identifier;
                }
            }
        }

        self::assertSame(
            [],
            $uncovered,
            'Neue Route ohne ACL-Abdeckung in diesem Test: ' . implode(', ', $uncovered)
        );
    }

    /**
     * @param class-string $controllerClass
     * @return array<string, mixed>
     */
    private function getRouteDefaults(string $controllerClass, string $methodName): array
    {
        $reflectionMethod = new \ReflectionMethod($controllerClass, $methodName);
        $routeAttributes = $reflectionMethod->getAttributes(Route::class);

        self::assertCount(
            1,
            $routeAttributes,
            sprintf('%s::%s() hat kein Route-Attribut', $controllerClass, $methodName)
        );

        return $routeAttributes[0]->newInstance()->getDefaults();
    }
}
