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
namespace MuckiFacilityPlugin\Core\Content\S3Connection;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CustomFields;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\RestrictDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

class S3ConnectionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_s3_connection';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return S3ConnectionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return S3ConnectionCollection::class;
    }

    /**
     * Every connection field is WriteProtected (system scope): writes only run through
     * Services\Content\S3ConnectionRepository, which validates the input and demands the
     * secret again when the target changes. A generic DAL write could otherwise re-target a
     * connection (endpoint, access key id, ...) and send the stored secret to another server.
     */
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('name', 'name'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('provider', 'provider', 32))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('endpoint', 'endpoint', 512))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('region', 'region', 64))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('access_key_id', 'accessKeyId'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('secret_access_key', 'secretAccessKey', 512))
                ->removeFlag(ApiAware::class)
                ->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('secret_source', 'secretSource', 16))
                ->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new CustomFields())->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),

            (new OneToManyAssociationField(
                'backupRepositories',
                BackupRepositoryDefinition::class,
                'mirror_s3_connection_id'
            ))->addFlags(new RestrictDelete()),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
