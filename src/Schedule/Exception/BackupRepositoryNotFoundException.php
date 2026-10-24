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

final class BackupRepositoryNotFoundException extends \RuntimeException
{
    public function __construct(string $repositoryId)
    {
        parent::__construct(sprintf('Backup repository "%s" was not found.', $repositoryId));
    }
}
