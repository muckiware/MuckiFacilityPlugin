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
namespace MuckiFacilityPlugin\Exception;

use Exception;

/**
 * Thrown when a table cleanup step fails.
 *
 * Must not be replaced by Doctrine\DBAL\Exception: that is a class in DBAL 3
 * (Shopware 6.6) but an interface in DBAL 4 (Shopware 6.7), so it cannot be
 * instantiated in both versions.
 */
class TableCleanupFailedException extends Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct(
        string $message = "Database table cleanup failed",
        int $code = 0,
        ?Exception $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
