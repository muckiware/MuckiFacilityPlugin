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

use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionDefinition;

/**
 * Guards the protection flags of the S3 connection (same reasoning as DatabaseConnectionDefinitionTest).
 * defineFields() is invoked via reflection, no Shopware bootstrap.
 */
class S3ConnectionDefinitionTest extends TestCase
{
    private FieldCollection $fields;

    protected function setUp(): void
    {
        $reflectionMethod = new \ReflectionMethod(S3ConnectionDefinition::class, 'defineFields');
        $reflectionMethod->setAccessible(true);

        $this->fields = $reflectionMethod->invoke(new S3ConnectionDefinition());
    }

    public function testEntityName(): void
    {
        self::assertSame('muwa_s3_connection', S3ConnectionDefinition::ENTITY_NAME);
    }

    public function testAllConnectionFieldsAreWriteProtectedToSystemScope(): void
    {
        foreach (['name', 'provider', 'endpoint', 'region', 'accessKeyId', 'secretAccessKey', 'secretSource', 'customFields'] as $propertyName) {
            $writeProtected = $this->getField($propertyName)->getFlag(WriteProtected::class);

            self::assertInstanceOf(WriteProtected::class, $writeProtected, $propertyName);
            self::assertTrue($writeProtected->isAllowed(Context::SYSTEM_SCOPE), $propertyName);
            self::assertFalse($writeProtected->isAllowed(Context::CRUD_API_SCOPE), $propertyName);
        }
    }

    public function testSecretIsNotReadableThroughTheApi(): void
    {
        self::assertFalse($this->getField('secretAccessKey')->is(ApiAware::class));
    }

    public function testDeletingAUsedConnectionIsRestricted(): void
    {
        self::assertTrue($this->getField('backupRepositories')->is(RestrictDelete::class));
    }

    public function testSecretColumnIsLongEnoughForCiphertext(): void
    {
        $field = $this->getField('secretAccessKey');
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
