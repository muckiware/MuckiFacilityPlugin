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
namespace MuckiFacilityPlugin\Core;

/**
 * Known database connection types. `type` is a free string so other plugins can register their own.
 */
class DatabaseTypes
{
    public const MYSQL = 'mysql';

    public const DEFAULT_PORTS = [
        self::MYSQL => 3306,
    ];
}
