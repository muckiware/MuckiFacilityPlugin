<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\DBAL\Connection;

use MuckiFacilityPlugin\MuckiFacilityPlugin;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksDefinition;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Snapshots\BackupRepositorySnapshotsDefinition;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Stats\BackupRepositoryStatsDefinition;

/**
 * Uninstalling without "keep data" used to leave all four `muwa_*` tables in place, repository
 * passwords included — the branch for that case (`uninstall()` without `keepUserData()`) was
 * empty. Verifies the private `dropPluginTables()` via reflection with a mocked Connection, so it
 * needs no Shopware bootstrap or real database.
 */
class MuckiFacilityPluginUninstallTest extends TestCase
{
    public function testUninstallDropsChildTablesBeforeTheRepositoryTableTheyReference(): void
    {
        $executedStatements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedStatements): int {
                $executedStatements[] = $sql;
                return 0;
            });

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with(Connection::class)
            ->willReturn($connection);

        $plugin = new MuckiFacilityPlugin(true, __DIR__);
        $plugin->setContainer($container);

        $dropPluginTables = new \ReflectionMethod(MuckiFacilityPlugin::class, 'dropPluginTables');
        $dropPluginTables->setAccessible(true);
        $dropPluginTables->invoke($plugin);

        self::assertCount(4, $executedStatements);

        $expectedOrder = [
            BackupRepositoryStatsDefinition::ENTITY_NAME,
            BackupRepositorySnapshotsDefinition::ENTITY_NAME,
            BackupRepositoryChecksDefinition::ENTITY_NAME,
            BackupRepositoryDefinition::ENTITY_NAME,
        ];

        foreach ($expectedOrder as $index => $tableName) {
            self::assertStringContainsString('DROP TABLE IF EXISTS', $executedStatements[$index]);
            self::assertStringContainsString(
                '`'.$tableName.'`',
                $executedStatements[$index],
                sprintf('Statement %d should target `%s`, ran: %s', $index, $tableName, $executedStatements[$index])
            );
        }

        self::assertStringContainsString(
            '`'.BackupRepositoryDefinition::ENTITY_NAME.'`',
            $executedStatements[array_key_last($executedStatements)],
            'muwa_backup_repository must be dropped last — the other three tables have a foreign key on it'
        );
    }
}
