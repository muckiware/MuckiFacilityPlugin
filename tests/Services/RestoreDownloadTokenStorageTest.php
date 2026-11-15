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
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

use MuckiFacilityPlugin\Services\RestoreDownloadToken;

/**
 * Prepare and download are two separate requests. cache.object is an in-memory ArrayAdapter in the
 * dev environment, so a token issued there was gone before the browser could redeem it.
 */
class RestoreDownloadTokenStorageTest extends TestCase
{
    use KernelTestBehaviour;

    public function testTokenStorageIsTheDatabaseNotARequestLocalCache(): void
    {
        $cache = (new \ReflectionProperty(RestoreDownloadToken::class, 'cache'))
            ->getValue($this->getContainer()->get(RestoreDownloadToken::class));

        static::assertInstanceOf(DoctrineDbalAdapter::class, $cache);
    }

    public function testTokenSurvivesTheResetBetweenTwoRequests(): void
    {
        $token = $this->getContainer()->get(RestoreDownloadToken::class)->issue('id', 'a/b.backup.sql');

        $this->getContainer()->get('services_resetter')->reset();

        static::assertSame(
            ['backupRepositoryId' => 'id', 'path' => 'a/b.backup.sql'],
            $this->getContainer()->get(RestoreDownloadToken::class)->redeem($token)
        );
    }
}
