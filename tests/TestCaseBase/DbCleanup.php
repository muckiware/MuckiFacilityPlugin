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
namespace MuckiFacilityPlugin\tests\TestCaseBase;
/**
 * Plugin wide default values
 */
final class DbCleanup
{
    public const CART_TEMP_TEST_TABLE_NAME = 'cart_temp_TEST';
    public const VALID_DAYS = 4;
    public const CART_TEMP_TEST_CREATE_STATEMENT = 'CREATE TABLE IF NOT EXISTS `cart` (
        `token` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `price` float DEFAULT NULL,
        `line_item_count` varchar(42) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `currency_id` binary(16) DEFAULT NULL,
        `shipping_method_id` binary(16) DEFAULT NULL,
        `payment_method_id` binary(16) DEFAULT NULL,
        `country_id` binary(16) DEFAULT NULL,
        `customer_id` binary(16) DEFAULT NULL,
        `sales_channel_id` binary(16) DEFAULT NULL,
        `rule_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        `created_at` datetime(3) NOT NULL,
        `updated_at` datetime(3) DEFAULT NULL,
        `auto_increment` bigint NOT NULL AUTO_INCREMENT,
        `compressed` tinyint(1) NOT NULL DEFAULT \'0\',
        `payload` longblob,
      PRIMARY KEY (`token`),
      UNIQUE KEY `auto_increment` (`auto_increment`),
      KEY `idx.cart.created_at` (`created_at`),
      CONSTRAINT `cart_chk_1` CHECK (json_valid(`rule_ids`))
    ) ENGINE=InnoDB AUTO_INCREMENT=1431860 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
}
