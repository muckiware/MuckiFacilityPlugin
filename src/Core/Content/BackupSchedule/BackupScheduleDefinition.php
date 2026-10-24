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
namespace MuckiFacilityPlugin\Core\Content\BackupSchedule;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{BoolField, CreatedAtField, DateTimeField, FkField, IdField, LongTextField, OneToOneAssociationField, StringField, UpdatedAtField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{ApiAware, CascadeDelete, PrimaryKey, Required, WriteProtected};
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

class BackupScheduleDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_backup_schedule';
    public function getEntityName(): string { return self::ENTITY_NAME; }
    public function getEntityClass(): string { return BackupScheduleEntity::class; }
    public function getCollectionClass(): string { return BackupScheduleCollection::class; }
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('backup_repository_id', 'backupRepositoryId', BackupRepositoryDefinition::class))->addFlags(new Required()),
            (new BoolField('enabled', 'enabled'))->addFlags(new Required(), new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('cron_expression', 'cronExpression', 255))->addFlags(new Required(), new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('timezone', 'timezone', 64))->addFlags(new Required(), new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('status', 'status', 16))->addFlags(new Required(), new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('last_execution_time', 'lastExecutionTime'))->addFlags(new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('next_execution_time', 'nextExecutionTime'))->addFlags(new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new LongTextField('last_error', 'lastError'))->addFlags(new ApiAware(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('run_token', 'runToken', 36))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new OneToOneAssociationField('backupRepository', 'backup_repository_id', 'id', BackupRepositoryDefinition::class, false))->addFlags(new CascadeDelete()),
            new CreatedAtField(), new UpdatedAtField(),
        ]);
    }
}
