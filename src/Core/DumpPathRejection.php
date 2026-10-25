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
namespace MuckiFacilityPlugin\Core;

enum DumpPathRejection: string
{
    case ROOT_DIRECTORY = 'rootDirectory';
    case RELATIVE_SEGMENT = 'relativeSegment';
    case PROJECT_DIR_OR_PARENT = 'projectDirOrParent';
    case OVERLAPS_REPOSITORY_OR_RESTORE_PATH = 'overlapsRepositoryOrRestorePath';
    case BLOCKED_PATH = 'blockedPath';
}
