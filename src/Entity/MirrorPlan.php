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
namespace MuckiFacilityPlugin\Entity;

/**
 * Immutable result of the mirror planning: what to upload, what to delete remotely.
 */
class MirrorPlan
{
    /**
     * @param list<string> $uploads
     * @param list<string> $deletes
     */
    public function __construct(
        protected array $uploads,
        protected array $deletes,
        protected int $unchangedCount
    ) {
    }

    /** @return list<string> */
    public function getUploads(): array
    {
        return $this->uploads;
    }

    /** @return list<string> */
    public function getDeletes(): array
    {
        return $this->deletes;
    }

    public function getUnchangedCount(): int
    {
        return $this->unchangedCount;
    }
}
