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
namespace MuckiFacilityPlugin\Events;

class RepositoryUploadSucceededEvent extends AbstractRepositoryUploadEvent
{
    public const EVENT_NAME = 'mucki_facility.repository_upload.succeeded';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
