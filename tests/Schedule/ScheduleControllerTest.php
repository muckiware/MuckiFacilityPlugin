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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScheduleControllerTest extends TestCase
{
    public function testInvalidCronExpressionReturnsBadRequest(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $controller = new ScheduleController(
            new ScheduleConfiguration($connection, new ScheduleCalculator())
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
        $controller = new ScheduleController(
            new ScheduleConfiguration($connection, new ScheduleCalculator())
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->configure(
            Uuid::randomHex(),
            new RequestDataBag(['enabled' => true, 'cronExpression' => '*/5 * * * *', 'timezone' => 'UTC']),
            Context::createDefaultContext()
        );
    }
}
