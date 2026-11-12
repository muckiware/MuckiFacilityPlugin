<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core\Content;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\RestrictDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionDefinition;

/**
 * Guards the protection flags of the database connection password (same reasoning as
 * BackupRepositoryDefinitionTest). defineFields() is invoked via reflection, no Shopware bootstrap.
 */
class DatabaseConnectionDefinitionTest extends TestCase
{
    private FieldCollection $fields;

    protected function setUp(): void
    {
        $reflectionMethod = new \ReflectionMethod(DatabaseConnectionDefinition::class, 'defineFields');
        $reflectionMethod->setAccessible(true);

        $this->fields = $reflectionMethod->invoke(new DatabaseConnectionDefinition());
    }

    public function testEntityName(): void
    {
        self::assertSame('muwa_database_connection', DatabaseConnectionDefinition::ENTITY_NAME);
    }

    public function testPasswordIsNotReadableThroughTheApi(): void
    {
        self::assertFalse(
            $this->getField('password')->is(ApiAware::class),
            'password must never be returned by the admin API'
        );
    }

    public function testPasswordAndSourceAreWriteProtectedToSystemScope(): void
    {
        foreach (['password', 'passwordSource'] as $propertyName) {
            $writeProtected = $this->getField($propertyName)->getFlag(WriteProtected::class);

            self::assertInstanceOf(WriteProtected::class, $writeProtected, $propertyName);
            self::assertTrue($writeProtected->isAllowed(Context::SYSTEM_SCOPE), $propertyName);
            self::assertFalse($writeProtected->isAllowed(Context::CRUD_API_SCOPE), $propertyName);
        }
    }

    /**
     * Without this a user with muwa_database_connection:update could re-target a connection
     * (host, username, ...) through the generic DAL API and receive the stored password.
     */
    public function testAllConnectionFieldsAreWriteProtectedToSystemScope(): void
    {
        foreach (['name', 'type', 'host', 'port', 'databaseName', 'username', 'customFields'] as $propertyName) {
            $writeProtected = $this->getField($propertyName)->getFlag(WriteProtected::class);

            self::assertInstanceOf(WriteProtected::class, $writeProtected, $propertyName);
            self::assertTrue($writeProtected->isAllowed(Context::SYSTEM_SCOPE), $propertyName);
            self::assertFalse($writeProtected->isAllowed(Context::CRUD_API_SCOPE), $propertyName);
        }
    }

    public function testDeletingAUsedConnectionIsRestricted(): void
    {
        self::assertTrue(
            $this->getField('backupRepositories')->is(RestrictDelete::class),
            'API delete of a used connection must give a restrict violation, not a raw FK error'
        );
    }

    public function testPasswordColumnIsLongEnoughForCiphertext(): void
    {
        $field = $this->getField('password');
        $reflectionProperty = new \ReflectionProperty($field, 'maxLength');
        $reflectionProperty->setAccessible(true);

        self::assertSame(512, $reflectionProperty->getValue($field));
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
