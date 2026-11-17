<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core\Content;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\Uploads\BackupRepositoryUploadsDefinition;

class BackupRepositoryUploadsDefinitionTest extends TestCase
{
    private FieldCollection $fields;

    protected function setUp(): void
    {
        $reflectionMethod = new \ReflectionMethod(BackupRepositoryUploadsDefinition::class, 'defineFields');
        $reflectionMethod->setAccessible(true);

        $this->fields = $reflectionMethod->invoke(new BackupRepositoryUploadsDefinition());
    }

    public function testEntityName(): void
    {
        self::assertSame('muwa_backup_repository_uploads', BackupRepositoryUploadsDefinition::ENTITY_NAME);
    }

    public function testTriggerColumnIsNotReservedWord(): void
    {
        self::assertSame('trigger_source', $this->getField('triggerSource')->getStorageName());
    }

    public function testAllHistoryFieldsExist(): void
    {
        foreach ([
            'backupRepositoryId', 'status', 'triggerSource', 'bucket', 'prefix', 's3ConnectionName',
            'startedAt', 'finishedAt', 'filesUploaded', 'filesDeleted', 'filesUnchanged',
            'bytesUploaded', 'message',
        ] as $propertyName) {
            self::assertSame($propertyName, $this->getField($propertyName)->getPropertyName());
        }
    }

    private function getField(string $propertyName): Field
    {
        /** @var Field $field */
        foreach ($this->fields as $field) {
            if ($field->getPropertyName() === $propertyName) {
                return $field;
            }
        }

        self::fail('Missing field: ' . $propertyName);
    }
}
