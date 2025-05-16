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
namespace MuckiFacilityPlugin\Core;

use Shopware\Core\Framework\Log\LogEntryDefinition;

enum CleanupTables: string
{
    case CART = 'cart';
    case LOG_ENTRY = LogEntryDefinition::ENTITY_NAME;
}
