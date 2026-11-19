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
namespace MuckiFacilityPlugin\tests\Services\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;

/**
 * Services\Backup saves the red "database dump failed" entry right after the green restic check.
 * Against the real DAL: the explicit createdAt must be stored, otherwise both entries could share
 * the timestamp and the green one could stay the latest check.
 */
class BackupRepositoryChecksOrderingTest extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;

    public function testExplicitCreatedAtMakesTheSecondEntryTheLatestCheck(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        // only the checks table is under test, the repository row is not needed
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $repositoryId = Uuid::randomHex();
            $checks = $this->getContainer()->get(BackupRepositoryChecks::class);

            $checks->saveNewCheck($repositoryId, 'no errors were found');
            $redId = $checks->saveNewCheck(
                $repositoryId,
                'database dump failed: boom',
                (new \DateTimeImmutable())->modify('+1 millisecond')
            );

            static::assertSame($redId, $checks->getLatestChecksByRepositoryId($repositoryId)?->getId());

            // proves the value is really written: an entry saved later but dated earlier is not the latest
            $checks->saveNewCheck($repositoryId, 'no errors were found', (new \DateTimeImmutable())->modify('-1 hour'));
            static::assertSame($redId, $checks->getLatestChecksByRepositoryId($repositoryId)?->getId());
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
