<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;
use MuckiFacilityPlugin\Core\Content\BackupSchedule\BackupScheduleDefinition;
use MuckiFacilityPlugin\Migration\Migration1792800000;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;

final class ScheduleSchemaTest extends TestCase
{
    public function testMigrationDefinesUniqueCascadeForeignKeyAndDueIndex(): void
    {
        $method = new \ReflectionMethod(Migration1792800000::class, 'sql');
        $method->setAccessible(true);
        $sql = $method->invoke(new Migration1792800000());
        self::assertStringContainsString('UNIQUE KEY `uniq.muwa_backup_schedule.repository_id`', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
        self::assertStringContainsString('KEY `idx.muwa_backup_schedule.due` (`enabled`, `next_execution_time`)', $sql);
        self::assertStringContainsString('`timezone` VARCHAR(64) NOT NULL DEFAULT \'UTC\'', $sql);
    }

    public function testDefinitionsExposeOneToOneAssociation(): void
    {
        $repositoryFields = $this->fields(new BackupRepositoryDefinition());
        $scheduleFields = $this->fields(new BackupScheduleDefinition());
        self::assertNotNull($repositoryFields->get('schedule'));
        self::assertNotNull($scheduleFields->get('backupRepository'));
    }

    public function testScheduleConfigurationAndRuntimeStateAreWriteProtectedFromGenericApi(): void
    {
        $fields = $this->fields(new BackupScheduleDefinition());

        foreach ([
            'enabled',
            'cronExpression',
            'timezone',
            'status',
            'lastExecutionTime',
            'nextExecutionTime',
            'lastError',
            'runToken',
        ] as $propertyName) {
            /** @var Field|null $field */
            $field = $fields->get($propertyName);
            self::assertNotNull($field, 'Missing schedule field: ' . $propertyName);

            $writeProtected = $field->getFlag(WriteProtected::class);
            self::assertInstanceOf(
                WriteProtected::class,
                $writeProtected,
                $propertyName . ' must only be writable through the validated schedule service'
            );
            self::assertTrue($writeProtected->isAllowed(Context::SYSTEM_SCOPE));
            self::assertFalse($writeProtected->isAllowed(Context::CRUD_API_SCOPE));
        }
    }

    public function testServiceConfigurationIsWellFormedXml(): void
    {
        $document = new \DOMDocument();
        self::assertTrue(
            $document->load(__DIR__ . '/../../src/Resources/config/services.xml'),
            'services.xml must remain valid XML'
        );
    }

    private function fields(object $definition): object
    {
        $method = new \ReflectionMethod($definition, 'defineFields');
        $method->setAccessible(true);
        $fields = $method->invoke($definition);
        $fields = $fields->compile(new \Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry(
            new \Symfony\Component\DependencyInjection\ContainerBuilder(), [], []
        ));
        return $fields;
    }
}
