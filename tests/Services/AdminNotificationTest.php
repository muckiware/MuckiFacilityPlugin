<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Services;

use MuckiFacilityPlugin\Services\AdminNotification;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

final class AdminNotificationTest extends TestCase
{
    public function testCreatesNotificationInSystemScope(): void
    {
        $data = ['id' => 'notification-id', 'status' => 'info', 'message' => 'message'];

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('create')
            ->with([$data], self::callback(
                static fn (Context $context): bool => $context->getScope() === Context::SYSTEM_SCOPE
            ));

        (new AdminNotification($repository))->createNotification($data, Context::createDefaultContext());
    }
}
