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

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class BackupRepositoryCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BackupRepositoryEntity::class;
    }
}
