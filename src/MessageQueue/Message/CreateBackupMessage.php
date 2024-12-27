<?php

namespace MuckiFacilityPlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;

class CreateBackupMessage extends CreateBackupEntity implements AsyncMessageInterface
{

}
