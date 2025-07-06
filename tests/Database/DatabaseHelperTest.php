<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Database;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Services\SettingsInterface;

class DatabaseHelperTest extends TestCase
{
    use KernelTestBehaviour;

    public function testCreateTempTable(): void
    {
        $databaseHelper = $this->createDatabaseHelperInstance();

        $columnExistsResult = $databaseHelper->columnExists('cart', 'token');
        $this->assertTrue($columnExistsResult, 'columnExists for token should results of true.');

        $columnExistsResult = $databaseHelper->columnExists('cart', 'smokey_and_the_Bandit_column');
        $this->assertFalse($columnExistsResult, 'columnExists for smokey_and_the_Bandit_column should results of true.');
    }
    private function createDatabaseHelperInstance(): DatabaseHelper
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $connection = $this->getContainer()->get(Connection::class);
        $settings = $this->createMock(SettingsInterface::class);

        return new DatabaseHelper($logger, $connection, $settings);
    }
}
