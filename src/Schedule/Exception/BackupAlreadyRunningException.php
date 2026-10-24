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
namespace MuckiFacilityPlugin\Schedule\Exception;

final class BackupAlreadyRunningException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another backup is already running.');
    }
}
