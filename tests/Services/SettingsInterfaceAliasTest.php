<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * Guards a regression where `MuckiFacilityPlugin\Services\SettingsInterface` was aliased to
 * `MuckiLogPlugin\Services\Settings` in services.xml — a copy-paste from another plugin's
 * namespace. It went unnoticed because every consumer takes the concrete `Services\Settings` by
 * argument, so Symfony optimizes away the unused private alias; the container only breaks once
 * something requests `SettingsInterface` through autowiring. Reads services.xml directly, so it
 * needs no Shopware bootstrap.
 */
class SettingsInterfaceAliasTest extends TestCase
{
    public function testSettingsInterfaceIsAliasedToThisPluginsOwnSettingsService(): void
    {
        $xml = new \DOMDocument();
        $xml->load(__DIR__ . '/../../src/Resources/config/services.xml');

        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('c', 'http://symfony.com/schema/dic/services');

        $services = $xpath->query('//c:service[@id="MuckiFacilityPlugin\Services\SettingsInterface"]');

        self::assertSame(1, $services->length, 'SettingsInterface service definition not found');

        /** @var \DOMElement $service */
        $service = $services->item(0);

        self::assertSame(
            'MuckiFacilityPlugin\Services\Settings',
            $service->getAttribute('alias'),
            'SettingsInterface must alias this plugin\'s own Settings service, not another plugin\'s'
        );
    }
}
