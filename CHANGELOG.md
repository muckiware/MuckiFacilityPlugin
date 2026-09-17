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

- Repository passwords are no longer stored as plain text. Until now the password that encrypts a
  restic repository sat readable in `muwa_backup_repository`, and the database dump this plugin
  creates contains that very table — so a single leaked dump was enough to decrypt every snapshot
  it was meant to protect.

  Every repository now records where its password comes from:

  | Source      | Content of `repository_password` | Secret in the database |
  |---|---|---|
  | `encrypted` | ciphertext, prefix `v1:`         | yes, encrypted         |
  | `env`       | name of an environment variable  | no                     |
  | `file`      | path of a password file          | no                     |
  | `plain`     | the password itself (legacy)     | yes, readable          |

  Repositories created in the administration use `encrypted` — XSalsa20-Poly1305 via libsodium,
  with a key derived from the new environment variable `MUWA_FACILITY_SECRET`. Deliberately not
  `APP_SECRET`: that is not meant to be an encryption key, and rotating it would render every
  repository password unreadable.

- `repository_password` and the new `password_source` are `WriteProtected` to the system scope.
  Both were previously writable through the generic DAL route by anyone holding
  `muwa_backup_repository:update` — the field was hidden from reads, but not from writes. They are
  now written only by the plugin itself.

- `POST /api/_action/muwa/backup/repository/init` persists the repository itself and only after
  `restic init` succeeded. Previously the administration saved the entity in a `.then()` chained
  behind the `.catch()`, so a failed init still produced a repository entry with no repository
  behind it.

### Upgrade notes
- Roles other than administrator lose access to the module until the new privileges are granted.
  Administrator accounts are unaffected.

- Add `MUWA_FACILITY_SECRET` to your `.env` before creating new repositories:

  ```shell
  echo "MUWA_FACILITY_SECRET=$(openssl rand -hex 32)" >> .env
  ```

  **Keep this value with your disaster recovery notes.** Without it no encrypted repository
  password can be read back, not even from a restored database.

- Existing repositories keep working unchanged — they are marked `plain` and need no secret. Move
  them to encrypted storage when ready:

  ```shell
  bin/console muckiware:backup:encrypt-passwords --dry-run
  bin/console muckiware:backup:encrypt-passwords
  ```

- To keep no secret in the database at all, point a repository at an environment variable or a
  file instead:

  ```shell
  bin/console muckiware:backup:password-source <backupRepositoryId> file /run/secrets/repo-password
  ```

- `repository_password` grows from `varchar(255)` to `varchar(512)` to make room for ciphertext.

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
