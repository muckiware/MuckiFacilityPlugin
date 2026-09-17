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
 * Thrown when the stored repository password cannot be turned into a usable password.
 *
 * Covers a failed decryption, a missing environment variable and an unreadable password file.
 * The message never contains the password itself — it is logged and shown in the administration.
 */
class UnresolvableRepositoryPasswordException extends Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = "Repository password could not be resolved",
        int $code = 0,
        ?\Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
