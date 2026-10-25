<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Writes administration notifications directly into the `notification` entity.
 * Shopware's own NotificationService lives in Shopware\Administration (6.6) or
 * Shopware\Core\Framework (6.7), the repository exists in both versions.
 */
class AdminNotification
{
    public function __construct(
        protected EntityRepository $notificationRepository
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function createNotification(array $data, Context $context): void
    {
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($data): void {
            $this->notificationRepository->create([$data], $context);
        });
    }
}
