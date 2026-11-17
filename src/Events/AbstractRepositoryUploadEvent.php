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

use MuckiFacilityPlugin\Entity\MirrorResult;
use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base for the Flow Builder events of the S3 repository mirror.
 * getName()/getAvailableData() must not depend on constructor state
 * (BusinessEventCollector instantiates without constructor).
 */
abstract class AbstractRepositoryUploadEvent extends Event implements FlowEventAware, MailAware, ScalarValuesAware
{
    public function __construct(
        protected string $backupRepositoryId,
        protected string $backupRepositoryName,
        protected MirrorResult $result,
        protected Context $context
    ) {
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('backupRepositoryId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('backupRepositoryName', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('status', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('bucket', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('prefix', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('s3ConnectionName', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('startedAt', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('finishedAt', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('filesUploaded', new ScalarValueType(ScalarValueType::TYPE_INT))
            ->add('filesDeleted', new ScalarValueType(ScalarValueType::TYPE_INT))
            ->add('bytesUploaded', new ScalarValueType(ScalarValueType::TYPE_INT))
            ->add('message', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getValues(): array
    {
        return [
            'backupRepositoryId' => $this->backupRepositoryId,
            'backupRepositoryName' => $this->backupRepositoryName,
            'status' => $this->result->getStatus(),
            'bucket' => $this->result->getBucket(),
            'prefix' => $this->result->getPrefix(),
            's3ConnectionName' => $this->result->getS3ConnectionName(),
            'startedAt' => $this->result->getStartedAt()->format(\DATE_ATOM),
            'finishedAt' => $this->result->getFinishedAt()->format(\DATE_ATOM),
            'filesUploaded' => $this->result->getFilesUploaded(),
            'filesDeleted' => $this->result->getFilesDeleted(),
            'bytesUploaded' => $this->result->getBytesUploaded(),
            'message' => $this->result->getMessage(),
        ];
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

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function getResult(): MirrorResult
    {
        return $this->result;
    }
}
