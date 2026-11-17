<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\CheckStatus;

class CheckStatusTest extends TestCase
{
    public function testHealthy(): void
    {
        self::assertTrue(CheckStatus::isHealthy('no errors were found'));
        self::assertTrue(CheckStatus::isHealthy('No Errors Were Found'));
        self::assertTrue(CheckStatus::isHealthy("  no errors were found\n"));
    }

    public function testNotHealthy(): void
    {
        self::assertFalse(CheckStatus::isHealthy('Fatal: repository contains errors'));
        self::assertFalse(CheckStatus::isHealthy(null));
        self::assertFalse(CheckStatus::isHealthy(''));
    }
}
