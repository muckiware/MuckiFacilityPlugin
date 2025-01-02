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

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class BackupRepositorySnapshotsCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BackupRepositorySnapshotsEntity::class;
    }
}
