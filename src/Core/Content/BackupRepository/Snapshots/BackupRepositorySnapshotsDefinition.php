<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Snapshots;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

class BackupRepositorySnapshotsDefinition extends EntityDefinition
{
    const ENTITY_NAME = 'muwa_backup_repository_snapshots';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return BackupRepositorySnapshotsEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BackupRepositorySnapshotsCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new idField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField(
                'backup_repository_id',
                'backupRepositoryId',
                BackupRepositoryDefinition::class
            ))->addFlags(new Required()),
            (new StringField('snapshot_id', 'snapshotId'))->addFlags(new ApiAware()),
            (new StringField('snapshot_short_id', 'snapshotShortId'))->addFlags(new ApiAware()),
            (new StringField('paths', 'paths'))->addFlags(new ApiAware()),
            (new StringField('size', 'size'))->addFlags(new ApiAware()),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
