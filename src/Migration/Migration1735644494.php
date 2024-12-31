<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1735644494 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735644494;
    }

    public function update(Connection $connection): void
    {

    }
}
