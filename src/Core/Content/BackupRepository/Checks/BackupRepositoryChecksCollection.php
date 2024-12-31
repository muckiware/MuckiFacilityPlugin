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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Checks;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class BackupRepositoryChecksCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BackupRepositoryChecksEntity::class;
    }
}
