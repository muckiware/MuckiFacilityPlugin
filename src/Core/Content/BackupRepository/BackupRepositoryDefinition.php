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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Inherited;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksDefinition;
class BackupRepositoryDefinition extends EntityDefinition
{
    const ENTITY_NAME = 'muwa_backup_repository';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return BackupRepositoryEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BackupRepositoryCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new idField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new BoolField('active', 'active'))->addFlags(new ApiAware(), new Inherited()),
            (new StringField('name', 'name'))->addFlags(new Required(), new ApiAware()),
            (new StringField('type', 'type'))->addFlags(new ApiAware()),
            (new StringField('repository_path', 'repositoryPath'))->addFlags(new Required(), new ApiAware()),
            (new StringField('repository_password', 'repositoryPassword'))->addFlags(new Required(), new ApiAware()),
            (new StringField('restore_path', 'restorePath'))->addFlags(new ApiAware()),
            (new JsonField('backup_paths', 'backupPaths', [], []))->addFlags(new ApiAware()),
            (new IntField('forget_daily', 'forgetDaily'))->addFlags(new ApiAware()),
            (new IntField('forget_weekly', 'forgetWeekly'))->addFlags(new ApiAware()),
            (new IntField('forget_monthly', 'forgetMonthly'))->addFlags(new ApiAware()),
            (new IntField('forget_yearly', 'forgetYearly'))->addFlags(new ApiAware()),

            (new OneToManyAssociationField(
                'backupRepositoryChecks',
                BackupRepositoryChecksDefinition::class,
                'backup_repository_id'
            )),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
