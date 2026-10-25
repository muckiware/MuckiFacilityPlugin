<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\Core;

enum BackupCommandTypes: string
{
    case NONE = 'none';
    case BATCH = 'batch';
    case PHP = 'php';
}
