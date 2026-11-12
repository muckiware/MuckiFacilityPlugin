<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

use MuckiFacilityPlugin\Controller\RestoreDirectoryController;
use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\RestoreDirectoryBrowser;
use MuckiFacilityPlugin\Services\RestoreDownloadToken;

class RestoreDirectoryControllerTest extends TestCase
{
    private const REPO_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';

    public function testDirectoryReturnsBrowserResult(): void
    {
        $result = ['state' => 'ok', 'path' => 'var', 'parent' => '', 'directories' => [], 'files' => []];
        $browser = $this->createMock(RestoreDirectoryBrowser::class);
        $browser->expects(static::once())->method('listDirectory')->with(self::REPO_ID, 'var')->willReturn($result);

        $response = $this->controller($browser)->directory(
            new RequestDataBag(['backupRepositoryId' => self::REPO_ID, 'path' => 'var']),
            Context::createDefaultContext()
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($result, json_decode((string) $response->getContent(), true));
    }

    public function testDirectoryReturns400OnInvalidPath(): void
    {
        $browser = $this->createMock(RestoreDirectoryBrowser::class);
        $browser->method('listDirectory')->willThrowException(new InvalidBrowsePathException('bad'));

        $response = $this->controller($browser)->directory(
            new RequestDataBag(['backupRepositoryId' => self::REPO_ID, 'path' => '../x']),
            Context::createDefaultContext()
        );

        static::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertSame('MUWA_INVALID_PATH', $body['errors'][0]['code']);
    }

    public function testInvalidRepositoryIdReturns400WithoutCallingBrowser(): void
    {
        $browser = $this->createMock(RestoreDirectoryBrowser::class);
        $browser->expects(static::never())->method('listDirectory');
        $browser->expects(static::never())->method('resolveDownloadFile');

        $controller = $this->controller($browser);
        $data = new RequestDataBag(['backupRepositoryId' => 'not-a-uuid', 'path' => 'a']);

        foreach ([
            $controller->directory($data, Context::createDefaultContext()),
            $controller->downloadPrepare($data, Context::createDefaultContext()),
        ] as $response) {
            static::assertSame(400, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true);
            static::assertSame('MUWA_INVALID_PATH', $body['errors'][0]['code']);
        }
    }

    public function testPrepareValidatesFileBeforeIssuingToken(): void
    {
        $browser = $this->createMock(RestoreDirectoryBrowser::class);
        $browser->method('resolveDownloadFile')->willThrowException(new InvalidBrowsePathException('bad'));
        $token = $this->createMock(RestoreDownloadToken::class);
        $token->expects(static::never())->method('issue');

        $response = $this->controller($browser, $token)->downloadPrepare(
            new RequestDataBag(['backupRepositoryId' => self::REPO_ID, 'path' => 'a.backup.sql']),
            Context::createDefaultContext()
        );

        static::assertSame(400, $response->getStatusCode());
    }

    public function testPrepareReturnsAccessToken(): void
    {
        $browser = $this->createMock(RestoreDirectoryBrowser::class);
        $browser->method('resolveDownloadFile')->willReturn('/x/a.backup.sql');
        $token = $this->createMock(RestoreDownloadToken::class);
        $token->expects(static::once())->method('issue')->with(self::REPO_ID, 'a.backup.sql')->willReturn('tok');

        $response = $this->controller($browser, $token)->downloadPrepare(
            new RequestDataBag(['backupRepositoryId' => self::REPO_ID, 'path' => 'a.backup.sql']),
            Context::createDefaultContext()
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['accessToken' => 'tok'], json_decode((string) $response->getContent(), true));
    }

    public function testDownloadWithUnknownTokenReturns404(): void
    {
        $token = $this->createMock(RestoreDownloadToken::class);
        $token->method('redeem')->willReturn(null);

        $response = $this->controller($this->createMock(RestoreDirectoryBrowser::class), $token)
            ->download(new Request(['accessToken' => 'nope']));

        static::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertSame('MUWA_INVALID_DOWNLOAD_TOKEN', $body['errors'][0]['code']);
    }

    public function testDownloadWithoutTokenReturns404(): void
    {
        $token = $this->createMock(RestoreDownloadToken::class);
        $token->expects(static::never())->method('redeem');

        $response = $this->controller($this->createMock(RestoreDirectoryBrowser::class), $token)
            ->download(new Request());

        static::assertSame(404, $response->getStatusCode());
    }

    public function testDownloadRevalidatesPathOnRedeem(): void
    {
        $token = $this->createMock(RestoreDownloadToken::class);
        $token->method('redeem')->willReturn(['backupRepositoryId' => self::REPO_ID, 'path' => 'a.backup.sql']);
        $browser = $this->createMock(RestoreDirectoryBrowser::class);
        $browser->method('resolveDownloadFile')->willThrowException(new InvalidBrowsePathException('gone'));

        $response = $this->controller($browser, $token)->download(new Request(['accessToken' => 'tok']));

        static::assertSame(400, $response->getStatusCode());
        static::assertNotInstanceOf(BinaryFileResponse::class, $response);
    }

    public function testDownloadStreamsFileAsAttachment(): void
    {
        $file = sys_get_temp_dir() . '/muwa-dl-' . bin2hex(random_bytes(4)) . '.backup.sql';
        file_put_contents($file, 'SELECT 1;');

        try {
            $token = $this->createMock(RestoreDownloadToken::class);
            $token->method('redeem')->willReturn(['backupRepositoryId' => self::REPO_ID, 'path' => 'a.backup.sql']);
            $browser = $this->createMock(RestoreDirectoryBrowser::class);
            $browser->expects(static::once())->method('resolveDownloadFile')
                ->with(self::REPO_ID, 'a.backup.sql')->willReturn($file);

            $response = $this->controller($browser, $token)->download(new Request(['accessToken' => 'tok']));

            static::assertInstanceOf(BinaryFileResponse::class, $response);
            static::assertSame($file, $response->getFile()->getPathname());
            $disposition = (string) $response->headers->get('Content-Disposition');
            static::assertStringContainsString('attachment', $disposition);
            static::assertStringContainsString(basename($file), $disposition);
            static::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        } finally {
            unlink($file);
        }
    }

    private function controller(
        RestoreDirectoryBrowser $browser,
        ?RestoreDownloadToken $token = null
    ): RestoreDirectoryController {
        return new RestoreDirectoryController(
            $browser,
            $token ?? $this->createMock(RestoreDownloadToken::class)
        );
    }
}
