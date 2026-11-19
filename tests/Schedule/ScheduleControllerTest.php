<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Controller\ScheduleController;
use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use MuckiFacilityPlugin\Schedule\ScheduleConfiguration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScheduleControllerTest extends TestCase
{
    public function testInvalidCronExpressionReturnsBadRequest(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $calculator = new ScheduleCalculator();
        $controller = new ScheduleController(
            new ScheduleConfiguration($connection, $calculator),
            $calculator
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->configure(
            Uuid::randomHex(),
            new RequestDataBag(['enabled' => true, 'cronExpression' => 'invalid', 'timezone' => 'UTC']),
            Context::createDefaultContext()
        );
    }

    public function testUnknownRepositoryReturnsNotFound(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');
        $calculator = new ScheduleCalculator();
        $controller = new ScheduleController(
            new ScheduleConfiguration($connection, $calculator),
            $calculator
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->configure(
            Uuid::randomHex(),
            new RequestDataBag(['enabled' => true, 'cronExpression' => '*/5 * * * *', 'timezone' => 'UTC']),
            Context::createDefaultContext()
        );
    }

    public function testPreviewReturnsUpcomingRunsInUtc(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $calculator = new ScheduleCalculator();
        $controller = new ScheduleController(new ScheduleConfiguration($connection, $calculator), $calculator);

        $response = $controller->preview(
            new RequestDataBag(['cronExpression' => '*/5 * * * *', 'timezone' => 'Europe/Berlin']),
            Context::createDefaultContext()
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $runs = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['nextRuns'];
        self::assertCount(ScheduleController::PREVIEW_RUN_COUNT, $runs);
        foreach ($runs as $run) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00\+00:00$/', $run);
        }
    }

    public function testPreviewWithInvalidCronExpressionReturnsBadRequest(): void
    {
        $calculator = new ScheduleCalculator();
        $controller = new ScheduleController(
            new ScheduleConfiguration($this->createMock(Connection::class), $calculator),
            $calculator
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->preview(
            new RequestDataBag(['cronExpression' => 'invalid', 'timezone' => 'UTC']),
            Context::createDefaultContext()
        );
    }
}
