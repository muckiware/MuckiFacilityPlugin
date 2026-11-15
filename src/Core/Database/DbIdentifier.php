<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core\Database;

/**
 * Validates database and table identifiers before they reach spatie/db-dumper.
 *
 * spatie builds the mysqldump command line as a SHELL string and inserts the
 * database name and the included table names unquoted and unescaped
 * (MySql::getDumpCommand() + Process::fromShellCommandline()). Anything outside
 * this identifier charset would therefore not end up as a mysqldump argument,
 * but as a command of the shop/worker shell — that is security finding S-01 of
 * the security review. The only values that are safe to pass are plain
 * identifiers without any shell metacharacter.
 */
final class DbIdentifier
{
    /** MySQL identifiers are at most 64 characters long. */
    public const MAX_LENGTH = 64;

    protected const PATTERN = '/^[A-Za-z0-9_$]+$/';

    /**
     * Safe identifier charset: letters, digits, underscore and dollar. This
     * excludes every shell metacharacter (space, ; | & ` $() quotes, leading
     * option dashes, line breaks) by construction.
     */
    public static function isValid(string $identifier): bool
    {
        return $identifier !== ''
            && strlen($identifier) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $identifier) === 1;
    }

    /**
     * @throws \InvalidArgumentException when the value is not a safe identifier
     */
    public static function assertValid(string $identifier, string $label): void
    {
        if (self::isValid($identifier)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            '%s must be 1 to %d characters from letters, digits, "_" and "$"',
            $label,
            self::MAX_LENGTH
        ));
    }
}
