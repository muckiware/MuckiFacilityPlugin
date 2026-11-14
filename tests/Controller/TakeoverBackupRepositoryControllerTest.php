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
namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

use MuckiFacilityPlugin\Controller\TakeoverBackupRepositoryController;
use MuckiFacilityPlugin\Exception\RepositoryTakeoverException;
use MuckiFacilityPlugin\Services\BackupPathSuggestions;
use MuckiFacilityPlugin\Services\RepositoryTakeover;

class TakeoverBackupRepositoryControllerTest extends TestCase
{
    private const PASSWORD = ' s3cr3t-pw ';

    protected function input(string $id): RequestDataBag
    {
        return new RequestDataBag([
            'id' => $id,
            'repositoryPath' => '/srv/repo',
            'repositoryPassword' => self::PASSWORD,
        ]);
    }

    public function testTakeoverReturnsIdAndCount(): void
    {
        $id = Uuid::randomHex();
        $takeover = $this->createMock(RepositoryTakeover::class);
        $takeover->expects(static::once())->method('takeover')
            ->with($id, '/srv/repo', self::PASSWORD)->willReturn(7);

        $response = (new TakeoverBackupRepositoryController($takeover, $this->createMock(BackupPathSuggestions::class)))
            ->takeover($this->input($id), Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            ['success' => true, 'id' => $id, 'snapshotsCount' => 7],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testTakeoverMissingValuesBecomeEmptyStrings(): void
    {
        $takeover = $this->createMock(RepositoryTakeover::class);
        $takeover->expects(static::once())->method('takeover')->with('', '', '')->willReturn(0);

        (new TakeoverBackupRepositoryController($takeover, $this->createMock(BackupPathSuggestions::class)))
            ->takeover(new RequestDataBag([]), Context::createDefaultContext());
    }

    public function testTakeoverErrorIsStructured(): void
    {
        $takeover = $this->createMock(RepositoryTakeover::class);
        $takeover->method('takeover')->willThrowException(new RepositoryTakeoverException(
            RepositoryTakeoverException::NOT_WRITABLE,
            'Not writable',
            ['user' => 'www-data']
        ));

        $response = (new TakeoverBackupRepositoryController($takeover, $this->createMock(BackupPathSuggestions::class)))
            ->takeover($this->input(Uuid::randomHex()), Context::createDefaultContext());

        static::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertSame(RepositoryTakeoverException::NOT_WRITABLE, $body['errors'][0]['code']);
        static::assertSame('Not writable', $body['errors'][0]['detail']);
        static::assertSame('www-data', $body['errors'][0]['meta']['user']);
    }

    public function testTakeoverResponseNeverContainsPassword(): void
    {
        $ok = $this->createMock(RepositoryTakeover::class);
        $ok->method('takeover')->willReturn(1);
        $failing = $this->createMock(RepositoryTakeover::class);
        $failing->method('takeover')->willThrowException(new RepositoryTakeoverException(
            RepositoryTakeoverException::ACCESS_FAILED,
            'Access failed',
            ['path' => '/srv/repo']
        ));

        foreach ([$ok, $failing] as $service) {
            $response = (new TakeoverBackupRepositoryController($service, $this->createMock(BackupPathSuggestions::class)))
                ->takeover($this->input(Uuid::randomHex()), Context::createDefaultContext());

            static::assertStringNotContainsString(trim(self::PASSWORD), (string) $response->getContent());
        }
    }

    public function testPathSuggestionsRejectsInvalidId(): void
    {
        $suggestions = $this->createMock(BackupPathSuggestions::class);
        $suggestions->expects(static::never())->method('collect');

        $response = (new TakeoverBackupRepositoryController($this->createMock(RepositoryTakeover::class), $suggestions))
            ->pathSuggestions(new RequestDataBag(['id' => 'nope']), Context::createDefaultContext());

        static::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertSame('MUWA_INVALID_ID', $body['errors'][0]['code']);
        static::assertArrayHasKey('detail', $body['errors'][0]);
    }

    public function testPathSuggestionsReturnsServiceResult(): void
    {
        $id = Uuid::randomHex();
        $result = ['suggestions' => [], 'truncated' => false, 'error' => null];
        $suggestions = $this->createMock(BackupPathSuggestions::class);
        $suggestions->expects(static::once())->method('collect')->with($id)->willReturn($result);

        $response = (new TakeoverBackupRepositoryController($this->createMock(RepositoryTakeover::class), $suggestions))
            ->pathSuggestions(new RequestDataBag(['id' => $id]), Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($result, json_decode((string) $response->getContent(), true));
    }
}
