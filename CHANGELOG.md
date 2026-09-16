# Changelog

All notable changes to this project will be documented in this file.

## [0.7.0]

### Security
- All six API routes now require an explicit ACL privilege. Until now they only carried
  `_routeScope: api`, which checks that a request is authenticated but not what the account is
  allowed to do. Any administration user or integration — regardless of how narrow its role was —
  could start backups, restore snapshots over production files and delete snapshots from the
  repository.

  | Route | Required privilege |
  |---|---|
  | `POST /api/_action/muwa/backup/process` | `muwa_backup_repository:backup` |
  | `POST /api/_action/muwa/backup/repository/init` | `muwa_backup_repository:create` |
  | `POST /api/_action/muwa/restore/process` | `muwa_backup_repository:restore` |
  | `POST /api/_action/muwa/manage/snapshots` | `muwa_backup_repository:read` |
  | `POST /api/_action/muwa/remove/snapshots` | `muwa_backup_repository:snapshot_delete` |
  | `GET /api/_action/muwa/repository/stats/{id}` | `muwa_backup_repository:read` |

- The administration module registers an ACL mapping, so the privileges can be assigned in the
  role editor. Restoring a snapshot and deleting snapshots are separate switches under
  "Additional permissions" — both are irreversible and must not come along as a side effect of
  "may edit" or "may delete".

### Upgrade notes
- Roles other than administrator lose access to the module until the new privileges are granted.
  Administrator accounts are unaffected.

### Added
- New tab "Repository Status" on the backup repository detail page, placed between
  Configuration and Checks.
- New table `muwa_backup_repository_stats` keeping a history of repository statistics per
  backup run.
- New CLI command `muckiware:repository:stats <backupRepositoryId>` to collect and persist the
  status independently of a backup run.

### Changed
- Repository statistics are no longer collected when the detail page is opened. They are
  collected at the end of every backup run, after removing snapshots via the administration, and
  on demand via the new CLI command. Opening the detail page of a large repository is no longer
  slow.

### Notes
- The route `GET /api/_action/muwa/repository/stats/{id}` is unchanged and still performs a live
  `restic stats` call.
- Existing repositories get their first status record with the next backup run or CLI call.
