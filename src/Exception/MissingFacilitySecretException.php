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
 * Thrown when MUWA_FACILITY_SECRET is missing or too short.
 *
 * Without the secret no encrypted repository password can be read or written. The exception
 * must stay loud: falling back to an unencrypted value would silently defeat the encryption.
 */
class MissingFacilitySecretException extends Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct(
        string $message = "Environment variable MUWA_FACILITY_SECRET is not set",
        int $code = 0,
        ?Exception $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
