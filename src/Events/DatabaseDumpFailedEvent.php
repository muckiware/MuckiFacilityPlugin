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
namespace MuckiFacilityPlugin\Events;

use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;
use Symfony\Contracts\EventDispatcher\Event;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

/**
 * Database dump of a backup run failed (also on the default connection, where the backup goes on
 * without the database). Flow Builder trigger. getName()/getAvailableData() must not depend on
 * constructor state (BusinessEventCollector instantiates without constructor).
 */
class DatabaseDumpFailedEvent extends Event implements FlowEventAware, MailAware, ScalarValuesAware
{
    public const EVENT_NAME = 'mucki_facility.database_dump.failed';

    /**
     * @param array<int, string> $failures password-masked error messages
     */
    public function __construct(
        protected BackupRepositorySettings $backupSettings,
        protected array $failures,
        protected bool $backupAborted,
        protected Context $context
    ) {
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('backupRepositoryId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('backupRepositoryName', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('failedCount', new ScalarValueType(ScalarValueType::TYPE_INT))
            ->add('backupAborted', new ScalarValueType(ScalarValueType::TYPE_BOOL))
            ->add('message', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getValues(): array
    {
        return [
            'backupRepositoryId' => $this->getBackupRepositoryId(),
            'backupRepositoryName' => $this->backupSettings->hasName() ? $this->backupSettings->getName() : '',
            'failedCount' => count($this->failures),
            'backupAborted' => $this->backupAborted,
            'message' => implode(PHP_EOL, $this->failures),
        ];
    }

    public function getBackupRepositoryId(): string
    {
        return $this->backupSettings->hasBackupRepositoryId() ? $this->backupSettings->getBackupRepositoryId() : '';
    }

    public function getBackupSettings(): BackupRepositorySettings
    {
        return $this->backupSettings;
    }

    /**
     * @return array<int, string>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    public function isBackupAborted(): bool
    {
        return $this->backupAborted;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getMailStruct(): MailRecipientStruct
    {
        return new MailRecipientStruct([]);
    }

    public function getSalesChannelId(): ?string
    {
        return null;
    }
}
