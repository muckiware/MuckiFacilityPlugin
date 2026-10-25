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

class InvalidBrowsePathException extends Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct(
        string $message = "Invalid path provided",
        int $code = 0,
        ?Exception $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
