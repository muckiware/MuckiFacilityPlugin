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

class RepositoryTakeoverException extends \RuntimeException
{
    public const INVALID_INPUT = 'MUWA_TAKEOVER_INVALID_INPUT';
    public const PATH_NOT_FOUND = 'MUWA_TAKEOVER_PATH_NOT_FOUND';
    public const NOT_A_REPOSITORY = 'MUWA_TAKEOVER_NOT_A_REPOSITORY';
    public const NOT_WRITABLE = 'MUWA_TAKEOVER_NOT_WRITABLE';
    public const DUPLICATE = 'MUWA_TAKEOVER_DUPLICATE';
    public const PASSWORD_NOT_STORABLE = 'MUWA_TAKEOVER_PASSWORD_NOT_STORABLE';
    public const ACCESS_FAILED = 'MUWA_TAKEOVER_ACCESS_FAILED';
    public const SAVE_FAILED = 'MUWA_TAKEOVER_SAVE_FAILED';
    public const IMPORT_FAILED = 'MUWA_TAKEOVER_IMPORT_FAILED';

    /**
     * @param array<string, string> $meta
     */
    public function __construct(
        protected string $errorCode,
        string $message,
        protected array $meta = [],
        ?\Throwable $previous = null
    )
    {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
