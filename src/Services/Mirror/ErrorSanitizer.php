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
namespace MuckiFacilityPlugin\Services\Mirror;

use Aws\Exception\AwsException;

/**
 * Turns an error into a short message that is safe to show or store: secrets are masked.
 */
class ErrorSanitizer
{
    public const MAX_LENGTH = 1000;

    public static function sanitize(\Throwable $error, string ...$secrets): string
    {
        if ($error instanceof AwsException) {
            $message = ($error->getAwsErrorCode() ?? 'UnknownError') . ': ' . ($error->getAwsErrorMessage() ?? $error->getMessage());
        } else {
            $message = get_class($error) . ': ' . $error->getMessage();
        }

        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        // invalid UTF-8 (e.g. raw process output) would break JSON encoding of history, events and API responses
        return mb_substr(mb_scrub($message, 'UTF-8'), 0, self::MAX_LENGTH, 'UTF-8');
    }
}
