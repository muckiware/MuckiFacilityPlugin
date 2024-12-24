<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Entity;

class ForgetTypes
{
    protected string $keepDaily;
    protected string $keepWeekly;
    protected string $keepMonthly;
    protected string $keepYearly;

    public function getKeepDaily(): string
    {
        return $this->keepDaily;
    }

    public function setKeepDaily(string $keepDaily): void
    {
        $this->keepDaily = $keepDaily;
    }

    public function getKeepWeekly(): string
    {
        return $this->keepWeekly;
    }

    public function setKeepWeekly(string $keepWeekly): void
    {
        $this->keepWeekly = $keepWeekly;
    }

    public function getKeepMonthly(): string
    {
        return $this->keepMonthly;
    }

    public function setKeepMonthly(string $keepMonthly): void
    {
        $this->keepMonthly = $keepMonthly;
    }

    public function getKeepYearly(): string
    {
        return $this->keepYearly;
    }

    public function setKeepYearly(string $keepYearly): void
    {
        $this->keepYearly = $keepYearly;
    }
}
