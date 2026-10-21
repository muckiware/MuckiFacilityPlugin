<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\ConfigPath;

/**
 * Guards a regression where the cleanup config keys pointed at `LightsOn.Library.config.*`
 * instead of `MuckiFacilityPlugin.config.*` — a copy-paste from another plugin's namespace that
 * silently made both cleanup settings unreadable (Shopware's SystemConfigService returns null
 * for an unknown key, so the 30-day fallback in Settings applied unconditionally).
 */
class ConfigPathTest extends TestCase
{
    public function testAllConfigPathsUseThePluginsOwnNamespace(): void
    {
        foreach (ConfigPath::cases() as $case) {
            self::assertStringStartsWith(
                'MuckiFacilityPlugin.config.',
                $case->value,
                $case->name.' must point at the MuckiFacilityPlugin config namespace'
            );
        }
    }
}
