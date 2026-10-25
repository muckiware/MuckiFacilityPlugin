<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

use MuckiFacilityPlugin\Controller\FilesystemController;
use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\DirectoryBrowser;

class FilesystemControllerTest extends TestCase
{
    public function testListDirectoriesReturnsBrowserResult(): void
    {
        $result = ['path' => '/mnt', 'parent' => '/', 'directories' => [], 'truncated' => false];
        $browser = $this->createMock(DirectoryBrowser::class);
        $browser->expects(static::once())->method('listDirectories')->with('/mnt')->willReturn($result);

        $response = (new FilesystemController($browser))
            ->listDirectories(new RequestDataBag(['path' => '/mnt']), Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($result, json_decode((string) $response->getContent(), true));
    }

    public function testListFilesReturnsBrowserResult(): void
    {
        $result = ['path' => '/mnt', 'parent' => '/', 'directories' => [], 'files' => [], 'truncated' => false];
        $browser = $this->createMock(DirectoryBrowser::class);
        $browser->expects(static::once())->method('listFiles')->with('/mnt', ['sh'])->willReturn($result);

        $response = (new FilesystemController($browser))
            ->listFiles(new RequestDataBag(['path' => '/mnt']), Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($result, json_decode((string) $response->getContent(), true));
    }

    public function testListDirectoriesReturns400OnInvalidPath(): void
    {
        $browser = $this->createMock(DirectoryBrowser::class);
        $browser->method('listDirectories')->willThrowException(new InvalidBrowsePathException('bad'));

        $response = (new FilesystemController($browser))
            ->listDirectories(new RequestDataBag(['path' => 'x']), Context::createDefaultContext());

        static::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertSame('MUWA_INVALID_PATH', $body['errors'][0]['code']);
        static::assertSame('bad', $body['errors'][0]['detail']);
    }

    public function testListDirectoriesTreatsNonStringPathAsNull(): void
    {
        $browser = $this->createMock(DirectoryBrowser::class);
        $browser->expects(static::once())->method('listDirectories')->with(null)->willReturn([]);

        $response = (new FilesystemController($browser))
            ->listDirectories(new RequestDataBag(['path' => ['x']]), Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
    }

    public function testCheckDumpPathPassesAllThreeFields(): void
    {
        $result = ['rejection' => null, 'writable' => true, 'resolvedPath' => '/a'];
        $browser = $this->createMock(DirectoryBrowser::class);
        $browser->expects(static::once())->method('checkDumpPath')->with('/a', '/b', null)->willReturn($result);

        $response = (new FilesystemController($browser))->checkDumpPath(
            new RequestDataBag(['dbDumpPath' => '/a', 'repositoryPath' => '/b', 'restorePath' => 42]),
            Context::createDefaultContext()
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($result, json_decode((string) $response->getContent(), true));
    }

    public function testCheckDumpPathReturns400OnInvalidPath(): void
    {
        $browser = $this->createMock(DirectoryBrowser::class);
        $browser->method('checkDumpPath')->willThrowException(new InvalidBrowsePathException('bad'));

        $response = (new FilesystemController($browser))->checkDumpPath(
            new RequestDataBag(['dbDumpPath' => "/a\n"]),
            Context::createDefaultContext()
        );

        static::assertSame(400, $response->getStatusCode());
    }
}
