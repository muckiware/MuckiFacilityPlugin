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
use Doctrine\DBAL\Exception;
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

        $tempTableResult = $cartCleanupRunner->createTempTable($cartCleanupRunner->getCreateTableStatement());
        $this->assertTrue($tempTableResult, 'Temporary table should be created successfully.');
    }

    public function testCreateTempTableException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Problem to create temp cart table');
        $cartCleanupRunner = $this->createRunnerInstance();

        $tempTableResult = $cartCleanupRunner->createTempTable('Testing invalid SQL statement');
    }

    public function checkOldTempTable(): void
    {
        $cartCleanupRunner = $this->createRunnerInstance();

        $cartCleanupRunner->createTempTable($cartCleanupRunner->getCreateTableStatement());
        $cartCleanupRunner->setCartTempTableName($cartCleanupRunner::CART_TEMP_TABLE_NAME);
        $oldTempTableResult = $cartCleanupRunner->checkOldTempTable();
        $this->assertTrue($oldTempTableResult, 'Temporary table should be created successfully.');
    }

    public function testRemoveOldTableItems()
    {
        $cartCleanupRunner = $this->createRunnerInstance();
        $this->prepareCartItems();
        $countCartItemsBefore = $cartCleanupRunner->countTableItems('cart');
        $this->assertEquals(3, $countCartItemsBefore, 'There should be 3 items in the cart table before cleanup.');

        try {
            $cartCleanupRunner->removeOldTableItems();
        } catch (Exception $e) {
            $this->fail('Exception occurred while removing old table items: ' . $e->getMessage());
        }
        $countCartItemsAfter = $cartCleanupRunner->countTableItems('cart');
        $this->assertEquals(1, $countCartItemsAfter, 'There should be 1 item in the cart table after cleanup.');
    }

    public function testCopyTableItemsIntoTempTable(): void
    {
        $cartCleanupRunner = $this->createRunnerInstance();

        $this->prepareCartTempTable();
        $this->prepareCartItems();
        $cartCleanupRunner->copyTableItems('cart', 'cart_temp');
        $countCartItems = $cartCleanupRunner->countTableItems('cart');
        $countCartTempItems = $cartCleanupRunner->countTableItems('cart_temp');

        $this->assertEquals($countCartTempItems, $countCartItems, 'Items of cart table and cart_temp table should be equal after copying items.');
    }

    private function prepareCartTempTable(): void
    {
        $cartCleanupRunner = $this->createRunnerInstance();
        $cartCleanupRunner->createTempTable($cartCleanupRunner->getCreateTableStatement());

        /** @var Connection $connection */
        $connection = $this->getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE `cart_temp`');
    }
    private function prepareCartItems(): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE `cart`');

        $sql = <<<'SQL'
            INSERT INTO `cart` (`token`, `payload`, `rule_ids`, `compressed`, `created_at`)
            VALUES (:token, :payload, :rule_ids, :compressed, :now)
            ON DUPLICATE KEY UPDATE `payload` = :payload, `compressed` = :compressed, `rule_ids` = :rule_ids, `created_at` = :now;
        SQL;
        $connection->executeStatement($sql, [
            'token' => 'test-token-1',
            'payload' => 'test-payload-1',
            'rule_ids' => json_encode(['rule1', 'rule2'], \JSON_THROW_ON_ERROR),
            'compressed' => 0,
            'now' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $sql = <<<'SQL'
            INSERT INTO `cart` (`token`, `payload`, `rule_ids`, `compressed`, `created_at`)
            VALUES (:token, :payload, :rule_ids, :compressed, :now)
            ON DUPLICATE KEY UPDATE `payload` = :payload, `compressed` = :compressed, `rule_ids` = :rule_ids, `created_at` = :now;
        SQL;
        $connection->executeStatement($sql, [
            'token' => 'test-token-2',
            'payload' => 'test-payload-2',
            'rule_ids' => json_encode(['rule1', 'rule2'], \JSON_THROW_ON_ERROR),
            'compressed' => 0,
            'now' => (new \DateTime())->modify('-5 days')->format('Y-m-d H:i:s'),
        ]);

        $sql = <<<'SQL'
            INSERT INTO `cart` (`token`, `payload`, `rule_ids`, `compressed`, `created_at`)
            VALUES (:token, :payload, :rule_ids, :compressed, :now)
            ON DUPLICATE KEY UPDATE `payload` = :payload, `compressed` = :compressed, `rule_ids` = :rule_ids, `created_at` = :now;
        SQL;
        $connection->executeStatement($sql, [
            'token' => 'test-token-3',
            'payload' => 'test-payload-3',
            'rule_ids' => json_encode(['rule1', 'rule2'], \JSON_THROW_ON_ERROR),
            'compressed' => 0,
            'now' => (new \DateTime())->modify('-6 days')->format('Y-m-d H:i:s'),
        ]);
    }

    private function createRunnerInstance(): CartCleanupRunner
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $connection = $this->getContainer()->get(Connection::class);
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getLastValidDateForCart')->willReturn(
            date(
                'Y-m-d',
                strtotime('-4 days', strtotime(date('Y-m-d')))
            )
        );
        $cliOutput = $this->createMock(CliOutput::class);
        $cliOutput->method('writeNewLineCliOutput')->willReturnCallback(function ($message) {
            echo $message . PHP_EOL;
        });

        return new CartCleanupRunner($logger, $connection, $settings, $cliOutput);
    }
}
