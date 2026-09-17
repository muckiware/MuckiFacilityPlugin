<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core\Content;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

/**
 * Sichert die Schutzflags am Passwortfeld ab.
 *
 * Durchgesetzt werden sie vom Core — ApiAware in den Encodern, WriteProtected in
 * Shopware\Core\Framework\DataAbstractionLayer\Write\WriteCommandExtractor. Was hier geprueft
 * wird, ist die Voraussetzung dafuer: dass die Flags ueberhaupt gesetzt sind. Faellt eines
 * davon bei einer spaeteren Aenderung weg, wird das Passwort still wieder les- oder
 * beschreibbar.
 *
 * defineFields() wird per Reflection aufgerufen, damit kein kompiliertes Definition-Registry
 * noetig ist und der Test ohne Shopware-Bootstrap laeuft.
 */
class BackupRepositoryDefinitionTest extends TestCase
{
    private FieldCollection $fields;

    protected function setUp(): void
    {
        $reflectionMethod = new \ReflectionMethod(BackupRepositoryDefinition::class, 'defineFields');
        $reflectionMethod->setAccessible(true);

        $this->fields = $reflectionMethod->invoke(new BackupRepositoryDefinition());
    }

    public function testRepositoryPasswordIsNotReadableThroughTheApi(): void
    {
        self::assertFalse(
            $this->getField('repositoryPassword')->is(ApiAware::class),
            'repositoryPassword must never be returned by the admin API'
        );
    }

    public function testRepositoryPasswordIsWriteProtectedToSystemScope(): void
    {
        $writeProtected = $this->getField('repositoryPassword')->getFlag(WriteProtected::class);

        self::assertInstanceOf(
            WriteProtected::class,
            $writeProtected,
            'repositoryPassword must not be writable through the generic DAL route'
        );
        self::assertTrue($writeProtected->isAllowed(Context::SYSTEM_SCOPE));
        self::assertFalse($writeProtected->isAllowed(Context::CRUD_API_SCOPE));
    }

    public function testPasswordSourceIsWriteProtectedToSystemScope(): void
    {
        // Ohne diesen Schutz liesse sich die Quelle auf 'plain' stellen, womit der
        // gespeicherte Chiffretext als Passwort interpretiert wuerde.
        $writeProtected = $this->getField('passwordSource')->getFlag(WriteProtected::class);

        self::assertInstanceOf(WriteProtected::class, $writeProtected);
        self::assertTrue($writeProtected->isAllowed(Context::SYSTEM_SCOPE));
        self::assertFalse($writeProtected->isAllowed(Context::CRUD_API_SCOPE));
    }

    public function testRepositoryPasswordColumnIsLongEnoughForCiphertext(): void
    {
        $field = $this->getField('repositoryPassword');
        $reflectionProperty = new \ReflectionProperty($field, 'maxLength');
        $reflectionProperty->setAccessible(true);

        self::assertSame(
            512,
            $reflectionProperty->getValue($field),
            'Ciphertext needs more room than the former varchar(255)'
        );
    }

    /**
     * Eine noch nicht kompilierte FieldCollection ist numerisch indiziert — get() greift
     * deshalb nicht auf den Property-Namen zu.
     */
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
