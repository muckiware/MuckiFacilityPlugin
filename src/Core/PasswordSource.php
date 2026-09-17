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

enum PasswordSource: string
{
    /**
     * old version of repository password storage in database as plain text, no longer supported.
     */
    case PLAIN = 'plain';
    case ENCRYPTED = 'encrypted';
    case ENV = 'env';
    case FILE = 'file';

    public function storesSecretInDatabase(): bool
    {
        return $this === self::PLAIN || $this === self::ENCRYPTED;
    }
}
