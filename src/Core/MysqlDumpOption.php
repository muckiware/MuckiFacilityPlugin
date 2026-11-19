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
 * Allowlist of the selectable mysqldump options.
 *
 * spatie/db-dumper puts every option unescaped into a shell command line, so there is no free text:
 * only these fixed arguments ever reach mysqldump. Stored by value (database connection field
 * `dump_options`, plugin config `mysqldump<Value>` for the default connection).
 */
enum MysqlDumpOption: string
{
    case NO_TABLESPACES = 'noTablespaces';
    case ROUTINES = 'routines';
    case EVENTS = 'events';
    case SET_GTID_PURGED_OFF = 'setGtidPurgedOff';
    case COLUMN_STATISTICS_OFF = 'columnStatisticsOff';
    case DEFAULT_CHARACTER_SET_UTF8MB4 = 'defaultCharacterSetUtf8mb4';
    case HEX_BLOB = 'hexBlob';

    /**
     * Used when nothing is configured: the behaviour before the options were selectable.
     */
    public const DEFAULTS = [self::NO_TABLESPACES];

    public function getArgument(): string
    {
        return match ($this) {
            self::NO_TABLESPACES => '--no-tablespaces',
            self::ROUTINES => '--routines',
            self::EVENTS => '--events',
            self::SET_GTID_PURGED_OFF => '--set-gtid-purged=OFF',
            self::COLUMN_STATISTICS_OFF => '--column-statistics=0',
            self::DEFAULT_CHARACTER_SET_UTF8MB4 => '--default-character-set=utf8mb4',
            self::HEX_BLOB => '--hex-blob',
        };
    }

    /**
     * Known values in a stable order (enum order, no duplicates); unknown values are dropped, so a
     * row written by an older or newer plugin version never breaks the dump.
     *
     * @param array<mixed> $values
     * @return array<int, self>
     */
    public static function fromValues(array $values): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $option): bool => in_array($option->value, $values, true)
        ));
    }

    /**
     * Strict variant for user input: every value must be known.
     *
     * @param array<mixed> $values
     * @return array<int, self>
     *
     * @throws \InvalidArgumentException
     */
    public static function fromInput(array $values): array
    {
        foreach ($values as $value) {
            if (!is_string($value) || self::tryFrom($value) === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown mysqldump option, allowed are: %s',
                    implode(', ', array_map(static fn (self $option): string => $option->value, self::cases()))
                ));
            }
        }

        return self::fromValues($values);
    }

    /**
     * @param array<int, self> $options
     * @return array<int, string>
     */
    public static function toValues(array $options): array
    {
        return array_values(array_map(static fn (self $option): string => $option->value, $options));
    }
}
