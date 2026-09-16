<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Stats;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

class BackupRepositoryStatsDefinition extends EntityDefinition
{
    const ENTITY_NAME = 'muwa_backup_repository_stats';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return BackupRepositoryStatsEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BackupRepositoryStatsCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('backup_repository_id', 'backupRepositoryId', BackupRepositoryDefinition::class))->addFlags(new Required()),
            (new IntField('total_size', 'totalSize'))->addFlags(new ApiAware()),
            (new IntField('total_file_count', 'totalFileCount'))->addFlags(new ApiAware()),
            (new IntField('snapshots_count', 'snapshotsCount'))->addFlags(new ApiAware()),
            (new IntField('file_system_size', 'fileSystemSize'))->addFlags(new ApiAware()),
            (new StringField('check_status', 'checkStatus'))->addFlags(new ApiAware()),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
