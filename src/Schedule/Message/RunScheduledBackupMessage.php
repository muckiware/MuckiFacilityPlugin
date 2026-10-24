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
namespace MuckiFacilityPlugin\Schedule\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Symfony\Component\Messenger\Handler\Acknowledger;

final readonly class RunScheduledBackupMessage implements AsyncMessageInterface
{
    public function __construct(public string $scheduleId, public string $repositoryId, public string $runToken) {}
}
