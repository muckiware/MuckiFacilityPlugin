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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Uploads;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\AllowEmptyString;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

/**
 * History of S3 mirror attempts per repository. Holds a snapshot of the target (no secrets).
 */
class BackupRepositoryUploadsDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_backup_repository_uploads';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return BackupRepositoryUploadsEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BackupRepositoryUploadsCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('backup_repository_id', 'backupRepositoryId', BackupRepositoryDefinition::class))->addFlags(new Required()),
            (new StringField('status', 'status', 16))->addFlags(new Required(), new ApiAware()),
            // "trigger" is a reserved word in MySQL, hence trigger_source.
            (new StringField('trigger_source', 'triggerSource', 16))->addFlags(new Required(), new ApiAware()),
            (new StringField('bucket', 'bucket'))->addFlags(new ApiAware()),
            (new StringField('prefix', 'prefix', 1024))->addFlags(new AllowEmptyString(), new ApiAware()),
            (new StringField('s3_connection_name', 's3ConnectionName'))->addFlags(new ApiAware()),
            (new DateTimeField('started_at', 'startedAt'))->addFlags(new ApiAware()),
            (new DateTimeField('finished_at', 'finishedAt'))->addFlags(new ApiAware()),
            (new IntField('files_uploaded', 'filesUploaded'))->addFlags(new ApiAware()),
            (new IntField('files_deleted', 'filesDeleted'))->addFlags(new ApiAware()),
            (new IntField('files_unchanged', 'filesUnchanged'))->addFlags(new ApiAware()),
            // BIGINT column; IntField hydrates to a 64-bit PHP int (Shopware has no BigIntField).
            (new IntField('bytes_uploaded', 'bytesUploaded'))->addFlags(new ApiAware()),
            (new LongTextField('message', 'message'))->addFlags(new AllowEmptyString(), new ApiAware()),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
