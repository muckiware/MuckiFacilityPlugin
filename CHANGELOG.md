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

- The database dump path (`db_dump_path` on a repository) can no longer be pointed at the
  repository's own storage or its restore path. That directory gets deleted recursively before
  and after every database backup, and both fields sit on the same admin form, so a
  copy-paste mistake was a realistic way to have a backup delete the very repository it was
  writing to. Verified against a real repository: setting the dump path to a subfolder of the
  repository path made the backup fall back to the default path and log the rejection, instead
  of wiping the repository.
- The dump path can also no longer be pointed at a number of sensitive system or project
  directories — `/etc`, `/var/log`, the project's `public`, `vendor`, `custom` and `.git`
  directories, among others. This is a blocklist of known-dangerous locations, not an
  allowlist: absolute paths outside the project (e.g. an external backup mount) remain
  supported, since that is an existing, deliberately tested capability of this field.
- `Services\Helper::deleteDirectory()` no longer follows symlinks. It used to recurse into a
  symlinked subdirectory as if it were a real one, so a symlink placed inside the dump
  directory could make the cleanup delete files far outside of it; and calling it on a dump
  path that was itself a symlink deleted through the link into its target. Both are refused
  now: the top-level path is rejected outright if it is a symlink, and every entry the walk
  finds is checked to still resolve inside the original directory before anything is removed.
  The method now returns `bool` instead of `void`; both call sites in
  `Services\Backup::runDatabaseBackup()` log an error when a deletion fails, instead of the
  failure passing by unnoticed and a half-cleaned directory ending up in the next snapshot.

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

### Fixed
- `bin/console muckiware:table:cleanup <cart|log_entry>` no longer has a window where the table
  does not exist at all. The previous strategy copied the remaining rows into a temp table, then
  ran `DROP TABLE` followed by `CREATE TABLE` and a copy back — a process kill, an out-of-memory
  kill or a lost database connection between those two statements left the shop without a `cart`
  (or `log_entry`) table, and every storefront request touching the cart failed until someone
  restored it by hand. The temp table is now swapped in with a single `RENAME TABLE original TO
  original_old, temp TO original` statement, which MySQL executes atomically — there is no
  intermediate state, and a failure leaves the original table untouched.
- Fixed a related latent bug this change would otherwise have made permanent: building the temp
  table's `CREATE TABLE` statement replaced every occurrence of the table name in the source
  schema, including inside index and constraint names such as `idx.cart.created_at`. That was
  harmless as long as the temp table stayed temporary, but the new swap turns it into the live
  table, which would have frozen the corrupted name in place — and since the temp table's own
  name (`cart_temp`) still contains the substring `cart`, every further cleanup run would have
  appended another `_temp`. The rename now targets only the `CREATE TABLE` header.
- A files backup no longer runs `restic unlock` and `restic prune` before every single
  configured backup path. `FilesRunner` reuses one restic client across all paths of a backup
  job, so with N configured paths, prune — the most expensive restic operation — used to run N
  times per job instead of once. Worse, `unlock` removes all locks on the repository
  unconditionally, including one held by a genuinely running concurrent operation, such as
  another backup job or a scheduled `muckiware:backup:forget`. Disk space is already reclaimed
  on its own schedule: `restic forget` always runs with `--prune`, and single-snapshot deletion
  from the administration explicitly prunes afterward.
- `bin/console muckiware:table:cleanup log_entry` deleted expired rows from `cart` instead of
  `log_entry` whenever the `log_entry` table had no `updated_at` column (a copy-paste bug in the
  branch that falls back to a `created_at`-only condition). `log_entry` itself never shrank, and
  `cart` lost rows the cart cleanup job was never asked to remove.
- `numberOfValidDaysInCart` and `numberOfValidDaysInLogEntry` pointed at `LightsOn.Library.config.*`
  instead of `MuckiFacilityPlugin.config.*` — a copy-paste from another plugin's namespace that made
  both settings unreadable, so cleanup always used the 30-day fallback regardless of what was
  configured. Added a regression test on `Core\ConfigPath` so this cannot silently regress again.
- `MuckiFacilityPlugin\Services\SettingsInterface` was aliased in `services.xml` to
  `MuckiLogPlugin\Services\Settings` — another plugin's class, in a different namespace. It never
  surfaced because every consumer takes the concrete `Services\Settings` by argument, so Symfony
  optimized away the unused alias; anything requesting `SettingsInterface` through autowiring would
  have crashed the container. Now aliased to this plugin's own `Services\Settings`, verified against
  the real container (`debug:container --show-hidden`), with a regression test reading the alias
  from `services.xml` directly.
- `bin/console plugin:uninstall MuckiFacilityPlugin` (without `--keep-user-data`) left all four
  `muwa_*` tables in place, repository passwords (or, for `env`/`file` sources, the environment
  variable name or file path pointing at them) included. `UninstallContext::keepUserData()` was
  already checked, but the branch for "don't keep it" was empty. Now drops
  `muwa_backup_repository_stats`, `_snapshots` and `_checks` before `muwa_backup_repository`
  itself, in that order, since the first three carry a foreign key on it. Passing
  `--keep-user-data` is unaffected and still skips this entirely.
- Four column migrations checked `INFORMATION_SCHEMA.COLUMNS` for an existing column without
  filtering by `TABLE_SCHEMA`, which lists columns of every database on the server. On a host
  running more than one shop, that check could find a same-named column in a different shop's
  database and silently skip the `ALTER TABLE` in the current one. Replaced with
  `MigrationStep::columnExists()`, which uses `SHOW COLUMNS` and is always scoped to the active
  connection.

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
