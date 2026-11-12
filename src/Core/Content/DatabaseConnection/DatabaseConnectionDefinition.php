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
namespace MuckiFacilityPlugin\Core\Content\DatabaseConnection;

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
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

class DatabaseConnectionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_database_connection';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return DatabaseConnectionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return DatabaseConnectionCollection::class;
    }

    /**
     * Every connection field is WriteProtected (system scope): writes only run through
     * Services\Content\DatabaseConnectionRepository, which validates the input and demands the
     * password again when the target changes. A generic DAL write could otherwise re-target a
     * connection (host, username, ...) and send the stored password to another server.
     */
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('name', 'name'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('type', 'type', 32))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('host', 'host'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IntField('port', 'port'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('database_name', 'databaseName'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('username', 'username'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('password', 'password', 512))
                ->removeFlag(ApiAware::class)
                ->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('password_source', 'passwordSource', 16))
                ->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new CustomFields())->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),

            (new OneToManyAssociationField(
                'backupRepositories',
                BackupRepositoryDefinition::class,
                'database_connection_id'
            ))->addFlags(new RestrictDelete()),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
