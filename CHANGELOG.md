# Changelog

All notable changes to this project will be documented in this file.

## [0.8.0]

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
