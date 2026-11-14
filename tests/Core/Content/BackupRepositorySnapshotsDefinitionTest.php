<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core\Content;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\Snapshots\BackupRepositorySnapshotsDefinition;

/**
 * defineFields() wird per Reflection aufgerufen, damit kein kompiliertes Definition-Registry
 * noetig ist (Stil wie BackupRepositoryDefinitionTest).
 */
class BackupRepositorySnapshotsDefinitionTest extends TestCase
{
    public function testPathsIsLongText(): void
    {
        $reflectionMethod = new \ReflectionMethod(BackupRepositorySnapshotsDefinition::class, 'defineFields');
        $reflectionMethod->setAccessible(true);

        /** @var FieldCollection $fields */
        $fields = $reflectionMethod->invoke(new BackupRepositorySnapshotsDefinition());
        $field = null;
        foreach ($fields as $candidate) {
            if ($candidate->getPropertyName() === 'paths') {
                $field = $candidate;
            }
        }

        self::assertInstanceOf(LongTextField::class, $field);
        self::assertTrue($field->is(ApiAware::class));
    }
}
