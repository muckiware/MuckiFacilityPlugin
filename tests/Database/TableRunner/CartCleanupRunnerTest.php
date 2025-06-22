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
namespace MuckiFacilityPlugin\tests\Database\TableRunner;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

use MuckiFacilityPlugin\Database\TableRunner\CartCleanupRunner;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\tests\TestCaseBase\DbCleanup as TestCaseBaseDbCleanup;

class CartCleanupRunnerTest extends TestCase
{
    use KernelTestBehaviour;
    public function testGetCreateTableStatement(): void
    {
        $cartCleanupRunner = $this->createRunnerInstance();
        $sqlStatement = $cartCleanupRunner->getCreateTableStatement();

        $this->assertIsString($sqlStatement, 'The SQL statement output should be a string.');
        $this->assertTrue(str_contains($sqlStatement, 'CREATE TABLE `cart`'), 'The SQL statement does not contain the expected CREATE TABLE statement.');
    }

    public function testCreateTempTable(): void
    {
        $cartCleanupRunner = $this->createRunnerInstance();

        $tempTableResult = $cartCleanupRunner->createTempTable(TestCaseBaseDbCleanup::CART_TEMP_TEST_CREATE_STATEMENT);
        $this->assertTrue($tempTableResult, 'Temporary table should be created successfully.');
    }

    public function checkOldTempTable(): void
    {
        $cartCleanupRunner = $this->createRunnerInstance();
        $cartCleanupRunner->setCartTempTableName(TestCaseBaseDbCleanup::CART_TEMP_TEST_TABLE_NAME);
        $oldTempTableResult = $cartCleanupRunner->checkOldTempTable();
        $this->assertTrue($oldTempTableResult, 'Temporary table should be created successfully.');
    }

    private function createRunnerInstance(): CartCleanupRunner
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $connection = $this->getContainer()->get(Connection::class);
        $settings = $this->createMock(SettingsInterface::class);
        $cliOutput = $this->createMock(CliOutput::class);
        $cliOutput->method('writeNewLineCliOutput')->willReturnCallback(function ($message) {
            echo $message . PHP_EOL;
        });

        return new CartCleanupRunner($logger, $connection, $settings, $cliOutput);
    }
}
