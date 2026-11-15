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
 * Thrown when the input of a database connection (save or test) is invalid.
 *
 * The message never contains the database password.
 */
class InvalidDatabaseConnectionException extends Exception
{
    public function __construct(string $message = "Invalid database connection", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
