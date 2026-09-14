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

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class BackupRepositoryStatsCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BackupRepositoryStatsEntity::class;
    }
}
