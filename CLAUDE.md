# MuckiFacilityPlugin — Claude-Kontext

Backup-, Restore- und Datenbank-Cleanup-Plugin fuer Shopware 6, basierend auf dem CLI-Tool
[restic](https://restic.readthedocs.io/). Die eigentliche restic-Ansteuerung liegt in der
Composer-Library `muckiware/restic`, dieses Plugin ist die Shopware-Integration darum herum.

**Wichtig:** Das ist kein LightsOn-Plugin — der Namespace ist `MuckiFacilityPlugin\`, **nicht**
`LightsOn\`, und die LightsOn-PHP-Standards (`final`, `readonly`, `private`) gelten hier nicht.

Dieses Verzeichnis ist **kein von Composer geliefertes Paket**, sondern ein Git-Checkout des
Upstream-Repos selbst (`git@github.com:muckiware/MuckiFacilityPlugin.git`, Branch `main`) unter
`custom/static-plugins/`. Der Maintainer von `muckiware/facility-plugin` ist t.freyda@lights-on.io —
also dieselbe Person, die hier arbeitet.

Daraus folgt: Aenderungen gehen als normaler Commit auf `main` in dieses Repo. **Kein** Fork, **kein**
Upstream-PR gegen Dritte, **kein** Composer-Patch — und Composer ueberschreibt hier nichts. Wer einen
Bug fixt, der auch andere Nutzer des Plugins betrifft, bumpt Version und `CHANGELOG.md` mit.

## Fakten

| | |
|---|---|
| Composer-Paket | `muckiware/facility-plugin`, Version `v0.9.0` |
| Plugin-Klasse | `MuckiFacilityPlugin\MuckiFacilityPlugin` |
| Shopware im Projekt | `shopware/core` **v6.7.14.0** (Arbeitsprojekt `sw67`; das Repo liegt ggf. in mehreren Shops) |
| Von Plugin unterstuetzt | Shopware 6.6.x + 6.7.x, PHP 8.3–8.5, restic >= 0.15 |
| CI-Matrix | PHP 8.2, MySQL 8.0, Shopware v6.6.9.0 (`.github/workflows/main.yml`) |
| Runtime-Deps | `muckiware/restic ^1.6`, `spatie/db-dumper ^3.7`, `gabrielelana/byte-units ^0.5`, `aws/aws-sdk-php ^3.369` |
| Git-Stand | Branch `main` (tracked `origin/main`), letzter Commit `8077d42` |

Es gibt **keine** Storefront-Integration (Controller/Templates/JS) — nur API-Routes, Admin-Modul
und CLI. Die `storefront.*.json`-Snippets enthalten `muwaSearch.suggest.*`-Keys aus einem anderen
Muckiware-Plugin und werden hier von nichts verwendet.

## Architektur

```
Admin (Vue) ──POST /api/_action/muwa/...──► Controller ──dispatch──► MessageQueue Handler
CLI (muckiware:*) ─────────────────────────► Commands ─┬─► Services\Backup ─► BackupRunnerFactory ─► BackupInterface-Runner
                                                        ├─► Services\RestoreSnapshot ─► MuckiRestic\Library\Restore
                                                        ├─► Services\ManageRepository ─► MuckiRestic\Library\Manage
                                                        ├─► Services\RepositoryStats ─► MuckiRestic\Library\Manage (restic stats) + Helper (Verzeichnisgroesse)
                                                        └─► Services\DbTableCleanup ─► TableCleanupRunnerFactory ─► TableCleanupInterface-Runner
```

`Services\RepositoryStats::collectAndSave()` wird dreifach getriggert: synchron am Ende von
`Services\Backup::createBackup()` (dort als `collectAndSaveForBackup()`, liefert die Id des Eintrags), asynchron ueber `UpdateRepositoryStatsMessage` /
`UpdateRepositoryStatsHandler` (dispatcht von `ManageController::removeSnapshots()`), und
synchron ueber den CLI-Command `muckiware:repository:stats` — letzterer bewusst synchron, damit
ein Cronjob auch ohne laufenden Messenger-Worker funktioniert.

**Backup-Laufzeit.** `createBackup()` misst per `hrtime()` vom Anfang (vor `BeforeBackupCreatedEvent`,
also inkl. Pre-Command) bis nach `AfterBackupCreatedEvent` (Post-Command) und schreibt im `finally`
ueber `RepositoryStats::saveBackupDuration()`: mit Stats-Id wird der Eintrag des Laufs aktualisiert,
ohne (Lauf vorher abgebrochen) wird ein neuer Eintrag mit dem aktuellen Stand angelegt, notfalls mit
leeren Werten. Wirft nie. Die Administration skaliert die Sekunden im gemeinsamen Helper
`module/muwa-backup-repository/helper/format-duration.js` auf Sek./Min./Std. (Snippets
`detail.durationSeconds|Minutes|Hours` mit `{value}`), genutzt von Detail-Seite und Dashboard-Widget.

Zwei Factory-Patterns tragen die ganze Fachlogik — bei neuen Backup- oder Cleanup-Typen sind
immer drei Stellen zu aendern: Enum + Factory + Runner.

### S3-Spiegelung
`Services\RepositoryMirror::mirror($repositoryId, $triggerSource, $checkId, $force)` spiegelt das lokale
restic-Repository in einen S3-Bucket (Hilfsklassen in `Services\Mirror\`: `MirrorPlanner`,
`LocalRepositoryScanner`, `MirrorKeyRules`, `MirrorTargetValidator`, `S3ClientFactory`, `ErrorSanitizer`).
Trigger: `Services\Backup::createBackup()` dispatcht nach dem Check-Item (`dispatchMirror()`) die
`MirrorRepositoryMessage`, nur wenn `mirror_s3_connection_id` gesetzt ist; ein Fehler beim Dispatch
bricht das Backup nie ab. Zweiter Weg: CLI `muckiware:repository:upload` (synchron). Das erste Spiegeln
eines grossen Repositories braucht evtl. mehrere Laeufe/Stunden und einen laufenden Messenger-Worker.
Ohne gesunden letzten Check wird uebersprungen (ausser `--force`). **Check-Gate:** Trigger `backup`
(`RepositoryMirror::TRIGGER_BACKUP`) ohne Check-Id wird immer uebersprungen, nie Fallback auf einen
aelteren (gruenen) Check; nur CLI ohne Check-Id nimmt den neuesten Check. Wirft `restic check` beim
Backup (`Services\Backup::checkBackup()`), speichert `createCheckItem()` einen roten Check
(`check failed: <sanitized>`, nie `CheckStatus::isHealthy()`) und gibt dessen Id an die Mirror-Message. Jeder Lauf schreibt einen Eintrag in
`muwa_backup_repository_uploads` und dispatcht `RepositoryUploadSucceededEvent` / `RepositoryUploadFailedEvent`
(Flow-Builder-Trigger `mucki_facility.repository_upload.succeeded|failed`).

**Loeschschutz.** `RepositoryMirror` ist die einzige Stelle, die remote loescht. Es werden nur Keys des
restic-Layouts (`data/`, `index/`, `keys/`, `snapshots/`, `MirrorKeyRules::isManaged()`) geloescht, `config`
nie. Abbruch ohne Remote-Aenderung bei lokalen restic-Locks (vor Upload und vor der Delete-Phase), bei
abweichender Remote-`config` und bei fehlenden lokalen Daten. Geloescht wird nur, wenn die Remote-`config`
zu Beginn des Laufs existierte (und passte) — der erste Lauf in ein Ziel laedt nur hoch. Diese
Schutzmechanismen nie abschwaechen.

### Backup-Pfad
`BackupTypes`-Enum (`src/Core/BackupTypes.php`) → `BackupRunnerFactory` → Runner:

| Enum-Wert | Runner | Was passiert |
|---|---|---|
| `completeDatabaseSingleFile` | `Backup\Database\CompleteFileRunner` | `spatie/db-dumper` mysqldump, eine Datei |
| `completeDatabaseSeparateFiles` | `Backup\Database\CompleteFilesRunner` | eine Dump-Datei pro Tabelle |
| `files` | `Backup\Files\FilesRunner` | restic-Snapshot ueber `MuckiRestic\Library\Backup` |
| `noneDatabase` | — | DB-Dump wird uebersprungen |

`Services\Backup::createBackup()` ist die Orchestrierung: erst DB-Dump in den Dump-Ordner, dann
diesen Ordner per `files`-Runner in das restic-Repository schieben, Dump-Ordner loeschen,
Files-Pfade sichern, Check-Item schreiben, Snapshots persistieren.

**Dump-Pfad.** Pro Repository ueber `db_dump_path` / `dbDumpPath` konfigurierbar, Fallback ist
`<projectDir>/var/db/backup` (`Defaults::DATABASE_BACKUP_PATH`). Aufgeloest wird in
`Settings::getBackupPath(bool $useSubFolder, ?string $ownDumpPath, ?string $repositoryPath,
?string $restorePath)`: ein Wert mit fuehrendem Schraegstrich gilt absolut, jeder andere relativ
zum Projektverzeichnis. `resolveOwnDumpPath()` verwirft unsichere Werte und faellt mit
Log-Eintrag auf den Default zurueck:

- leer, `/`, Segmente `.` oder `..`, das Projektverzeichnis selbst oder ein Elternverzeichnis davon
- Ueberschneidung mit `repositoryPath` oder `restorePath` desselben Repositories, in beide
  Richtungen (`pathsOverlap()`) — beide Felder stehen im selben Admin-Formular, ein
  Copy-Paste-Fehler wuerde sonst das restic-Repository selbst loeschen
- eine feste Blockliste bekannter gefaehrlicher Systemverzeichnisse (`/etc`, `/var/log`, ...) und
  sensibler Projekt-Unterordner (`public`, `vendor`, `custom`, `.git`, `var/log`, `var/cache`,
  ...) — keine Allowlist: ein absoluter Pfad ausserhalb des Projekts (externer Backup-Mount)
  bleibt bewusst erlaubt, das ist eine bestehende, in `SettingsTest.php` getestete Faehigkeit

Die beiden neuen Parameter sind optional (Default `null`) und werden vom Aufrufer nur befuellt,
wenn er sie hat — `CompleteFileRunner`/`CompleteFilesRunner`/`Services\Backup` nutzen dafuer
`BackupRepositorySettings::hasRepositoryPath()` / `hasRestorePath()`, weil `restorePath` beim
Backup-Lauf (anders als beim Restore) nie gesetzt wird und ein direkter Getter-Aufruf mit
`\Error` abbrechen wuerde.

**Gemeinsame Regelquelle.** `Settings::getOwnDumpPathRejection()` (liefert `?Core\DumpPathRejection`)
ist die einzige Stelle fuer die Dump-Pfad-Regeln: `resolveOwnDumpPath()` (Fallback im Backup) und
`DirectoryBrowser::checkDumpPath()` (Warnung in der Administration) nutzen sie beide. Regeln nur
dort aendern, sonst laufen Backup-Verhalten und Admin-Warnung auseinander.

Analog fuer den Restore-Pfad: `Settings::getRestorePathRejection()` (ebenfalls `?Core\DumpPathRejection`)
ist die einzige Regelquelle. Zusaetzlich zu den Standardregeln liefert sie
`DumpPathRejection::OVERLAPS_DUMP_PATH`, wenn der Restore-Pfad sich mit dem Dump-Pfad (eigener oder
Default) ueberschneidet. `RestoreDirectoryBrowser` nutzt sie und prueft den real aufgeloesten Restore-Root erneut.

Das ist kein Schoenheitsthema: der Dump-Ordner wird vor **und** nach jedem DB-Backup per
`Helper::deleteDirectory()` rekursiv geloescht. Diese Methode folgt keinen Symlinks mehr (weder
als Top-Level-Pfad noch beim Durchlaufen), gibt `bool` zurueck statt `void`, und beide Aufrufer
in `runDatabaseBackup()` loggen einen Fehler, wenn sie `false` zurueckbekommt. Das Loeschen danach
steht im `finally`: auch ein abgebrochener Lauf darf keinen Klartext-Dump liegen lassen. Ausnahme ist
`muckiware:db:dump` (`createDump()`), dessen Zweck gerade die Dump-Datei ist.

**Falle: `backupPaths` wird mitten im Lauf ueberschrieben.** `runDatabaseBackup()` setzt
`$createBackup->setBackupPaths([<Dump-Ordner>])` und den Typ auf `files`, damit derselbe
`FilesRunner` den Dump ins restic-Repository schiebt. Nach diesem Aufruf enthaelt
`$createBackup->getBackupPaths()` also **nicht** mehr die konfigurierten Datei-Pfade.
`createBackup()` cached sie deshalb vorher in `$cachePaths` — und jede Pruefung auf "gibt es
ueberhaupt Datei-Pfade?" muss gegen `$cachePaths` laufen, nicht gegen den Getter. Genau das war ein
Bug: bei einem Repository mit leeren `backup_paths` wurde ein Files-Backup ohne Pfade gestartet,
`FilesRunner::createBackupData()` lief durch die leere Schleife, und `getBackupResults()` griff auf
die uninitialisierte Property `$backupResults` zu. Regressionstests dazu in
`tests/Services/BackupTest.php` und `tests/Backup/Files/FilesRunnerTest.php`.

Zwei Konsequenzen fuer aehnliche Aenderungen: Runner-Properties, die erst in einer Schleife
befuellt werden, brauchen einen Default (`= []`) — und `startBackupRunner()` / `checkBackup()`
fangen nur `\Exception`, kein `\Error`, ein `\Error` reisst den ganzen Lauf ungeloggt ab.

**`FilesRunner::prepareBackupClient()` setzt `setSkipPrepareBackup(true)`.** Ohne das ruft
`MuckiRestic\Library\Backup\Local::createBackup()` vor jedem einzelnen `restic backup`-Aufruf
`restic unlock` und `restic prune` auf. `createBackupData()` nutzt denselben Client fuer alle
konfigurierten Pfade eines Backup-Laufs — bei N Pfaden liefe `prune` (die teuerste
restic-Operation) N mal statt einmal pro Lauf, und `unlock` entfernt dabei bedingungslos **alle**
Locks auf dem Repository, auch die eines echten, parallel laufenden Vorgangs (z. B. ein zweiter
Backup-Lauf oder das taeglich per Cron laufende `muckiware:backup:forget`). Speicherplatz wird
davon unabhaengig freigegeben: `restic forget` laeuft immer mit `--prune`
(`MuckiRestic\Library\CommandLine\Commands\Forget`), und das Loeschen einzelner Snapshots aus der
Administration ruft danach explizit `ManageRepository::cleanupRepository()` auf. Ein manuelles
`restic unlock` nach einem nachweislich abgestuerzten Lauf gibt es im Plugin nicht als eigenen
CLI-Befehl — bislang war das kein Problem, weil der automatische Unlock diesen Fall zufaellig mit
abdeckte.

**Datenbankverbindung (Event-basiert).** `Services\DatabaseConnectionResolver` loest vor jedem DB-Dump
und jedem Verbindungstest `Events\ResolveDatabaseConnectionEvent` (`mucki_facility.database_connection.resolve`)
aus und liefert ein `Entity\DatabaseBackupTarget` (`ResolvedDatabaseConnection`, `DatabaseDumperInterface`,
`isDefault()`, Verbindungsname). `CompleteFileRunner`/`CompleteFilesRunner` bekommen das Target und
dumpen nur ueber den Dumper; `CompleteFilesRunner` nimmt die Tabellenliste von `$dumper->listTables()`.
`Core\Database\Database` (`CoreDatabase`) wird nur noch innerhalb von `Backup\Database\MysqlDatabaseDumper`
fuer die Standardverbindung genutzt. Eingebauter Handler ist `Subscriber\MysqlDatabaseConnectionSubscriber`
(Prioritaet 0, nur Typ `mysql`, fuellt nur, was noch fehlt); Plugins mit hoeherer Prioritaet duerfen
Verbindung und/oder Dumper ersetzen. Typ der Standardverbindung kommt aus dem Schema von `DATABASE_URL`
(unbekanntes Schema = eigener Typ, ohne Subscriber => Fehler; ohne Schema `mysql`).

**Dump-Datei und Shell.** spatie baut mysqldump als Shell-String und setzt die Ausgabedatei nur in
doppelte Anfuehrungszeichen (escaped nur `\` und `"`) — `$(...)`/Backticks im Pfad wuerden ausgefuehrt.
`MysqlDatabaseDumper::runDump()` ersetzt deshalb `MySql::dumpToFile()`: mysqldump bekommt nur einen
generierten Namen `.muwa-dump-<hex>.partial` und laeuft mit dem Dump-Ordner als cwd, erst nach Erfolg
wird per `rename()` auf den Zielnamen umbenannt, sonst wird die Partial-Datei geloescht. Nie zurueck
auf `dumpToFile()` stellen. `runDump()` nutzt spatie-Interna (`guardAgainstIncompleteCredentials`,
`setTempFileHandle`, `getProcess`, `checkIfDumpWasSuccessFul`) — bei spatie-Updates pruefen.
Zusaetzlich lehnt `Settings::getOwnDumpPathRejection()` Steuerzeichen, `$`, Backtick, `"` und `\` ab
(`DumpPathRejection::INVALID_CHARACTERS`).

**mysqldump-Optionen.** Nur aus der Allowlist `Core\MysqlDumpOption` (Enum mit festem Argument je
Fall) — **nie Freitext**, spatie setzt Optionen ungeschuetzt in den Shell-String. Quelle: eigene
Verbindung `muwa_database_connection.dump_options` (ListField, `WriteProtected(SYSTEM_SCOPE)`, NULL =
`MysqlDumpOption::DEFAULTS` = nur `--no-tablespaces`), Standardverbindung je ein Bool
`mysqldump<Value>` in `config.xml` (`Settings::getDefaultConnectionDumpOptions()`, nie gespeichert =
Default). Der Subscriber legt sie als `extra['dumpOptions']` (`MysqlDatabaseDumper::EXTRA_DUMP_OPTIONS`)
in die `ResolvedDatabaseConnection`, der Dumper filtert erneut ueber `fromValues()`; fehlt der Key
(fremder Subscriber), gelten die Defaults. Neue Option = Enum-Fall + `config.xml`-Bool + `DUMP_OPTIONS`
in `muwa-database-connection-detail/index.js` + Snippets; `MysqlDumpOptionTest` prueft die
Gleichlaeufigkeit von Enum, `config.xml` und Administration.

**Fehlersemantik.** Standardverbindung (`database_connection_id` NULL): das Backup laeuft weiter, der
Fehler wird aber sichtbar gemacht. Die Runner sammeln ihn (`DumpFailureAwareInterface::getDumpFailures()`),
`Services\Backup::handleDumpFailures()` loest `DatabaseDumpFailedEvent` (Flow-Builder-Trigger
`mucki_facility.database_dump.failed`, statt `AfterDatabaseDumpCreatedEvent`) aus, und
`createCheckItem()` speichert **nach** dem restic-Check einen zusaetzlichen roten Check
`database dump failed: …` mit `createdAt` +1 ms (sonst koennte der gruene gleich alt sein und der
neueste bleiben). Die Mirror-Message bekommt bewusst die Id des **restic**-Checks — das Repository ist
intakt, die Spiegelung laeuft. `Backup::getDumpFailure()` liefert den Status; `BackupExecution` meldet
dann `backupDumpFailed` (warning) statt `backupSucceeded`. Mit `failOnDumpError` bricht der Lauf wie bei
eigenen Verbindungen ab. Eine leere Tabellenliste (`CompleteFilesRunner`) zaehlt als Dump-Fehler —
`Core\Database\Database::getListOfAllTables()` schluckt DB-Fehler und liefert dann `[]`. mysqldump
laeuft mit Timeout (`dbDumpTimeout`, Minuten, Default `Defaults::DB_DUMP_TIMEOUT_MINUTES` = 240), weil
ein haengender Dump sonst den globalen Lock `mucki-facility.backup` (TTL `null`) fuer immer haelt;
`ProcessTimedOutException` wird im Dumper zum normalen Dump-Fehler. Eigene Verbindung: jeder Fehler (Verbindung
nicht gefunden, Passwort nicht aufloesbar, kein Subscriber/Dumper, Dump fehlgeschlagen) wirft
`DatabaseBackupFailedException` mit Verbindungsnamen und bricht den ganzen `createBackup()`-Lauf ab; es
gibt **keinen** Fallback auf `DATABASE_URL` und keinen leeren Dump im restic-Repository.

**DB-Passwort.** `Services\Content\DatabasePasswordResolver`: Quellen `encrypted`/`env`/`file`, kein
`plain`. `env` nur mit Praefix `MUWA_DB_`, `file` nur unterhalb `<projectDir>/var/secrets/` (`realpath()`,
relative Pfade gelten relativ zum Projektverzeichnis, nicht zum CWD), Pruefung beim Speichern und
beim Aufloesen; NUL-Bytes in Referenzen werden abgewiesen. Passwoerter mit Zeilenumbruch werden
abgewiesen (spatie schreibt sie ungeschuetzt in die mysqldump-Credentials-Datei); dasselbe gilt fuer
Zeilenumbrueche/NUL in Name, Host, User und Datenbankname (beim Speichern in
`DatabaseConnectionRepository`, beim Aufloesen nochmals im `MysqlDatabaseConnectionSubscriber`).
Passwoerter mit `'` funktionieren mit spatie nicht. Aenderung von Host/Port/Typ/User oder der
Passwortquelle verlangt ein neu eingegebenes Passwort. Loeschen einer genutzten Verbindung scheitert
an `RestrictDelete` (DAL) bzw. am FK (`RESTRICT`), die Administration zeigt die nutzenden Konfigurationen.

### Cleanup-Pfad
`CleanupTables`-Enum → `TableCleanupRunnerFactory` → `CartCleanupRunner` / `LogEntryCleanupRunner`
(beide erben von `CleanupRunner`, implementieren `TableCleanupInterface`).

Strategie: `SHOW CREATE TABLE` → Temp-Tabelle anlegen → alte Rows in Originaltabelle loeschen →
Rest in Temp kopieren → Temp per `RENAME TABLE original TO original_old, temp TO original`
atomar an die Stelle der Originaltabelle setzen → `original_old` droppen. Das gibt anders als ein
reines `DELETE` den Speicher auf der Platte wieder frei. Bis einschliesslich `facility-plugin
v0.7.0` lief der letzte Schritt stattdessen ueber `DROP TABLE` + `CREATE TABLE` + Ruecckopieren —
destruktiv und nicht transaktional, mit einem Fenster, in dem die Tabelle gar nicht existierte,
wenn der Prozess dazwischen abgebrochen wurde. `RENAME TABLE` mit zwei Paaren ist dagegen eine
einzige atomare Anweisung; es gibt kein Zwischenstadium und keinen Datenverlust bei einem Absturz
mittendrin. Trotzdem bleibt das Prinzip: vor Aenderungen an diesem Pfad immer einen Dump ziehen.

**Falle: `createTempTable()` darf nur den Tabellennamen ersetzen, nicht das ganze Statement.**
`SHOW CREATE TABLE` liefert Index- und Constraint-Namen wie `idx.cart.created_at`, die den
Tabellennamen als Teilstring enthalten. Ein blindes `str_replace('cart', 'cart_temp', $sql)`
trifft auch diese Namen. Solange die Temp-Tabelle danach wieder verworfen wurde, war das
folgenlos — seit dem Swap per `RENAME TABLE` wird die Temp-Tabelle aber zur Live-Tabelle, und der
verunstaltete Name (`idx.cart_temp.created_at`) bliebe dauerhaft stehen. Schlimmer: `cart_temp`
enthaelt selbst den Teilstring `cart`, sodass derselbe Fehler bei jedem weiteren Lauf einen
zusaetzlichen `_temp`-Suffix anhaengen wuerde. Ersetzt wird deshalb gezielt nur
`` CREATE TABLE `cart` `` durch `` CREATE TABLE `cart_temp` ``, siehe
`CartCleanupRunner::createTempTable()` / `LogEntryCleanupRunner::createTempTable()` und
`tests/Database/TableRunner/TempTableNamingTest.php`.

## Entities

Alle Tabellen kommen aus `src/Migration/`, DAL-Registrierung per `shopware.entity.definition`-Tag.

| Entity / Tabelle | Zweck | Relationen |
|---|---|---|
| `muwa_backup_repository` | Repository-Konfiguration (Pfad, Passwort, Hostname, Restore-Pfad, `db_dump_path`, `backup_paths` als JsonField, forget-Policy, `database_connection_id` NULL = Standardverbindung) | 1:n Checks, 1:n Snapshots, n:1 Datenbankverbindung (`ON DELETE RESTRICT`) |
| `muwa_backup_repository_checks` | Ergebnis von `restic check` je Backup-Lauf | n:1 Repository |
| `muwa_backup_repository_snapshots` | gespiegelte restic-Snapshot-Liste (snapshotId, shortId, paths, hostname, size) | n:1 Repository |
| `muwa_backup_repository_stats` | Historie der Repository-Statistik je Backup-Lauf (Rohwerte in Bytes/Anzahl), `backup_duration` = Laufzeit des Backup-Laufs in Sekunden (`Migration1795093023`, NULL bei Stats ausserhalb eines Laufs) | n:1 Repository |
| `muwa_database_connection` | Eigene Datenbankverbindung fuer das DB-Backup (`name`, `type`, `host`, `port`, `database_name`, `username`, `password`, `password_source`, `dump_options`, `custom_fields`), Migrationen `Migration1794470400`, `Migration1794916800` (`dump_options`) | 1:n Backup-Repositories |
| `muwa_s3_connection` | S3-Verbindung (`name`, `provider` aws/minio/ceph/other, `endpoint`, `region`, `access_key_id`, `secret_access_key`, `secret_source`, `custom_fields`), Migration `Migration1794830400` | 1:n Backup-Repositories (`ON DELETE RESTRICT`) |
| `muwa_backup_repository_uploads` | Historie der Spiegel-Laeufe (`status`, `trigger_source`, `bucket`, `prefix`, `s3_connection_name`, Zeiten, `files_uploaded/deleted/unchanged`, `bytes_uploaded`, `message`) | n:1 Repository (`ON DELETE CASCADE`) |
| `muwa_backup_repository.mirror_s3_connection_id` / `mirror_s3_bucket` / `mirror_s3_prefix` | Spiegelziel je Repository, NULL = keine Spiegelung | n:1 S3-Verbindung |

`repository_password` traegt `removeFlag(ApiAware::class)` — das Passwort darf nie ueber die
Admin-API rausgehen. Bei Aenderungen an der Definition darauf achten. Gleiches gilt fuer `password` /
`password_source` von `muwa_database_connection`. Dort sind **alle** Felder (`name`, `type`, `host`,
`port`, `database_name`, `username`, `password`, `password_source`, `dump_options`, `custom_fields`)
`WriteProtected(SYSTEM_SCOPE)` und werden nur ueber `Services\Content\DatabaseConnectionRepository`
im System-Scope geschrieben — sonst koennte ein Nutzer mit `update`-Recht die Verbindung per
generischer Entity-API auf einen fremden Host umbiegen und bekaeme dort das gespeicherte Passwort.

Alle Felder von `muwa_s3_connection` sowie die drei `mirror_s3_*`-Spalten sind `WriteProtected(SYSTEM_SCOPE)`:
Geschrieben wird nur ueber `S3ConnectionController::save` bzw. `BackupRepositoryMirrorController::update`.
Grund: Die Spiegelung loescht remote; sonst koennte ein Nutzer mit `update`-Recht per Entity-API das Ziel auf einen
fremden Bucket umbiegen und die gespeicherten Zugangsdaten dorthin schicken (analog `muwa_database_connection`).
`secret_access_key` / `secret_source` sind nie `ApiAware`.

Plain-PHP-Structs (kein DAL) in `src/Entity/`: `BackupRepositorySettings` (das zentrale DTO, wird
von `CreateBackupMessage` geerbt), `BackupPathEntity`, `ForgetTypes`, `RepositoryInitInputs`,
`CreateBackupEntity`.

## API-Routes (nur `_routeScope: api`)

Registrierung per Attribut, eingelesen ueber `Resources/config/routes.xml`.

| Route | Controller | Admin-Aufrufer |
|---|---|---|
| `POST /api/_action/muwa/backup/process` | `BackupController` | Detail-Seite, Button "Start backup" |
| `POST /api/_action/muwa/backup/repository/init` | `InitBackupRepositoryController` | Create-Seite |
| `POST /api/_action/muwa/restore/process` | `RestoreSnapshotController` | Detail-Seite, Snapshot-Tab |
| `POST /api/_action/muwa/manage/snapshots` | `ManageController::getSnapshots` | — |
| `POST /api/_action/muwa/remove/snapshots` | `ManageController::removeSnapshots` | Snapshot-Tab |
| `GET /api/_action/muwa/repository/stats/{id}` | `ManageController::getRepositoryStats` | — (live `restic stats`, von der Administration nicht mehr aufgerufen; siehe Repository-Status-Tab) |
| `POST /api/_action/muwa/filesystem/directories` | `FilesystemController::listDirectories` | Ordner-Browser (Pfad-Felder), ACL `muwa_backup_repository:update` |
| `POST /api/_action/muwa/filesystem/check-dump-path` | `FilesystemController::checkDumpPath` | Live-Warnung am Dump-Pfad, ACL `muwa_backup_repository:update` |
| `POST /api/_action/muwa/restore/directory` | `RestoreDirectoryController::directory` | Snapshot-Tab, Komponente `muwa-restore-directory-tree`, ACL `muwa_backup_repository:restore` |
| `POST /api/_action/muwa/restore/download/prepare` | `RestoreDirectoryController::downloadPrepare` | wie oben; stellt ein Einmal-Token aus, ACL `muwa_backup_repository:restore` |
| `POST /api/_action/muwa/restore/delete` | `RestoreDirectoryController::delete` | wie oben, Link "Löschen" je Dump mit Bestaetigungs-Modal; loescht nur Dumps (keine Symlinks) und raeumt leer gewordene Elternordner bis exkl. Restore-Root auf, ACL `muwa_backup_repository:restore` |
| `GET /api/_action/muwa/restore/download` | `RestoreDirectoryController::download` | Browser-Download, **`auth_required: false`**, abgesichert nur ueber das Token |
| `POST /api/_action/muwa/database-connection/save` | `DatabaseConnectionController::save` | Datenbankverbindungen-Tab, ACL `muwa_database_connection:update`, Neuanlage zusaetzlich `muwa_database_connection:create` (im Controller geprueft) |
| `POST /api/_action/muwa/database-connection/test` | `DatabaseConnectionController::testConnection` | Button "Verbindung pruefen", ACL `muwa_database_connection:update`; ungueltige Felder 400 `MUWA_INVALID_DATABASE_CONNECTION`; Passwortprobleme (fehlt, Zeilenumbruch, Ziel geaendert ohne Passwort) und Verbindungsfehler HTTP 200 mit `success=false` |
| `POST /api/_action/muwa/s3-connection/save` | `S3ConnectionController::save` | S3-Verbindungen-Tab, ACL `muwa_s3_connection:update` (Neuanlage zusaetzlich `:create`, im Controller geprueft); ungueltige Felder 400 `MUWA_INVALID_S3_CONNECTION`, fehlendes Recht `MUWA_MISSING_PRIVILEGE` |
| `POST /api/_action/muwa/s3-connection/test` | `S3ConnectionController::testConnection` | Button "Verbindung pruefen", ACL `muwa_s3_connection:update`; Verbindungsfehler HTTP 200 mit `success=false` |
| `PUT /api/_action/muwa/backup/{backupRepositoryId}/mirror` | `BackupRepositoryMirrorController::update` | Detail-Seite, Abschnitt S3-Spiegelung, ACL `muwa_backup_repository:update`; 400 `MUWA_INVALID_ID`, `MUWA_INVALID_MIRROR_TARGET`, `MUWA_MIRROR_TARGET_OVERLAP` (Ueberschneidung mit Ziel eines anderen Repositories), 404 unbekanntes Repository, 204 bei Erfolg; einziger Schreibweg der `mirror_s3_*`-Felder |
| `GET /api/_action/muwa/restic/info` | `ResticController::getInfo` | Plugin-Konfiguration, Komponente `muwa-restic-binary-info`, ACL `system_config:read` |
| `POST /api/_action/muwa/backup/repository/takeover` | `TakeoverBackupRepositoryController::takeover` | Modal `muwa-backup-repository-add-modal` (Liste, "Repository hinzufuegen"), ACL `muwa_backup_repository:create`; Fehler 400 `{errors:[{code,detail,meta}]}` mit `MUWA_TAKEOVER_*` (u.a. `MUWA_TAKEOVER_INVALID_INPUT` fuer ungueltige Id; `MUWA_TAKEOVER_SAVE_FAILED` bei DB-Fehlern; `MUWA_TAKEOVER_ACCESS_FAILED` traegt die restic-Ausgabe in `meta.detail`); `MUWA_INVALID_ID` kommt nur von path-suggestions |
| `POST /api/_action/muwa/backup/repository/path-suggestions` | `TakeoverBackupRepositoryController::pathSuggestions` | Detail-Seite, Tab Konfiguration (Pfad-Vorschlaege), ACL `muwa_backup_repository:update` |
| `POST /api/_action/muwa/backup/schedule/preview` | `ScheduleController::preview` | Detail-Seite, Tab Zeitplan (Live-Vorschau beim Tippen, debounced), ACL `muwa_backup_repository:read`; liefert `{nextRuns: [...]}` mit `ScheduleController::PREVIEW_RUN_COUNT` (5) Terminen in UTC (ISO 8601) ueber `ScheduleCalculator::nextRuns()` — dieselbe Berechnung wie der Scheduler, keine eigene Cron-Logik im Admin; ungueltiger Ausdruck/Zeitzone 400; speichert nichts |

Die Rolle `muwa_backup_repository.viewer` enthaelt auch `muwa_backup_repository_uploads:read` (Upload-Historie,
S3-Dashboard-Widget) und `muwa_s3_connection:read`.

Die Rollen fuer `muwa_database_connection:*` (read/update/create/delete) haengen in den bestehenden
Rollen von `muwa_backup_repository`, es gibt keine eigene Rollengruppe.

`Services\ResticBinaryInfo` liefert Pfad und Version des restic-Binarys fuer die **gespeicherte**
Konfiguration (eigener Pfad nur bei `useOwnResticPath` + gesetztem Pfad, sonst `restic` aus `PATH`)
und markiert Versionen unter `MIN_SUPPORTED_VERSION` (0.15.0) — darunter antwortet `restic init`
nicht mit JSON und die Repository-Initialisierung scheitert mit "JSON Syntax error".

**Token-geschuetzte Public-Route.** Der Browser-Download kann keinen Bearer-Header senden, daher ist
`GET .../restore/download` oeffentlich (`auth_required: false`). Schutz: `Services\RestoreDownloadToken`
(60 s TTL, einmalig einloesbar, an Repository-ID + relativen Pfad gebunden), ausgestellt
durch die ACL-geschuetzte `prepare`-Route. Gespeichert werden die Tokens im Service
`muwa.restore_download_token.cache` (`DoctrineDbalAdapter`, Tabelle `muwa_restore_download_token` aus
`Migration1794744000`, bei jedem `issue()` per `prune()` aufgeraeumt). **Nicht** zurueck auf
`cache.object` stellen: Shopware setzt `cache.app` in `dev` auf `cache.adapter.array`, dort ueberlebt
der Token den Prepare-Request nicht (404 `MUWA_INVALID_DOWNLOAD_TOKEN`). Die test-Umgebung biegt
`cache.object` auf Filesystem um und verdeckt das — daher `RestoreDownloadTokenStorageTest`. `RestoreDirectoryBrowser` listet Ordner und alle regulaeren
Dateien, `downloadable` ist nur fuer Dumps (`.backup.sql`, `.backup.sql.gz`, `.backup.sql.tar.gz`) true;
`resolveDownloadFile()` lehnt alles andere ab. Symlinks werden uebersprungen, und der Browser liefert die Zustaende
`ok`/`notConfigured`/`notAllowed`/`empty`. `RouteAclTest` fuehrt diese Route explizit als einzige
erlaubte Public-Route; weitere Public-Routes duerfen dort nicht ohne Begruendung ergaenzt werden.

Die beiden `filesystem`-Routes antworten bei ungueltiger Eingabe mit 400 `MUWA_INVALID_PATH`.
`Services\DirectoryBrowser` ist rein lesend und listet nur Ordner: logische Pfade ohne
`realpath()`, bei nicht existierendem Pfad der naechste vorhandene Elternordner, die Liste wird auf
`MAX_ENTRIES` (1000) gekuerzt (`truncated`-Flag), Namen die kein gueltiges UTF-8 sind werden uebersprungen.

## CLI-Commands

| Command | Klasse | Argument |
|---|---|---|
| `muckiware:backup:create` | `Commands\BackupCreate` | `backupRepositoryId` |
| `muckiware:backup:check` | `Commands\BackupCheck` | `backupRepositoryId` |
| `muckiware:backup:snapshots` | `Commands\ManageSnapshots` | `backupRepositoryId` |
| `muckiware:repository:stats` | `Commands\RepositoryStats` | `backupRepositoryId` |
| `muckiware:backup:forget` | `Commands\ManageForget` | `backupRepositoryId` |
| `muckiware:backup:restore` | `Commands\RestoreSnapshot` | `backupRepositoryId`, `snapshotId` |
| `muckiware:repository:upload` | `Commands\RepositoryUpload` | `backupRepositoryId`, Option `--force` (spiegeln trotz ungesundem letzten Check); synchron, bricht ab solange ein Backup laeuft (Lock `mucki-facility.backup`) |
| `muckiware:db:dump` | `Commands\Dump` | `backupType` (BackupTypes-Enum) |
| `muckiware:table:cleanup` | `Commands\DbTableCleanup` | `tableName` (`cart`, `log_entry`) |

Basisklasse `Commands\Commands` validiert Argumente (`Uuid::isValid`, Enum-Abgleich).
Alle Commands sind in `services.xml` mit `console.command` registriert, nicht per Autoconfigure.

## MessageQueue

`CreateBackupMessage` und `RestoreSnapshotMessage` erben beide `BackupRepositorySettings` und
implementieren `AsyncMessageInterface`. Handler: `CreateBackupHandler`, `RestoreSnapshotHandler`
(beide `#[AsMessageHandler]` **und** zusaetzlich per `messenger.message_handler`-Tag in
`services.xml` — doppelte Registrierung).

Passwoerter werden **nicht** in die Message serialisiert: der Handler laedt das Repository frisch
und setzt das Passwort erst dann (`setRepositoryPassword()`). Dieses Muster beibehalten.

`UpdateRepositoryStatsMessage` / `UpdateRepositoryStatsHandler` sind der dritte Trigger fuer
`Services\RepositoryStats::collectAndSave()`, dispatcht von `ManageController::removeSnapshots()`.
Die Message erbt **nicht** von `BackupRepositorySettings` — sie traegt nur die
`backupRepositoryId` —.

`MirrorRepositoryMessage` / `MirrorRepositoryHandler` (`#[AsMessageHandler]`, ebenfalls Handler-Tag in
`services.xml`) fuehren die S3-Spiegelung aus. Die Message traegt nur Repository-Id und Check-Id, nie Secrets.
Der Handler wirft nie (Fehler werden geloggt), damit der Messenger nicht redelivert. Parallelitaet verhindert
der Symfony-Lock `mucki-facility.mirror.<repositoryId>` (`RepositoryMirror::LOCK_PREFIX`, TTL 3600 s, wird
waehrend des Laufs refreshed); ist er belegt, wird still uebersprungen.

`TakeoverRepositoryCheckMessage` / `TakeoverRepositoryCheckHandler` werden nach einer Repository-
Uebernahme (`Services\RepositoryTakeover`) dispatcht: `restic check` (ohne `--read-data`,
`ManageRepository::checkRepositoryStructure()`), dann Statistik, dann Admin-Benachrichtigung. Der
Handler wirft nie (Fehler werden als Benachrichtigung gemeldet); auch er hat Handler-Tag in
`services.xml`. Die Uebernahme liest Snapshots per `ManageRepository::readSnapshots()`
(`restic snapshots --no-lock`) und ruft kein `init`/`unlock`/`prune`/`forget` auf.

## Konfiguration

`src/Resources/config/config.xml` definiert:
`active`, `activeDbBackup`, `compressDbBackup`, `failOnDumpError`, `dbDumpTimeout`, `mysqldump<Option>` (je ein Bool pro
`Core\MysqlDumpOption`, nur Standardverbindung), `useOwnResticPath`, `ownPathResticBinary`,
`numberOfValidDaysInCart`, `numberOfValidDaysInLogEntry`, `showDashboardWidget`,
`showS3MirrorDashboardWidget`.

`showDashboardWidget` und `showS3MirrorDashboardWidget` werden nur von der Administration gelesen
(`systemConfigApiService`), nicht von PHP — deshalb gibt es dafuer keinen `ConfigPath`-Eintrag.

Zugriff ausschliesslich ueber `Services\Settings` + `Core\ConfigPath`-Enum. `Settings` liest
zusaetzlich `DATABASE_URL` via `EnvironmentHelper` und loest den Dump-Pfad auf — der haengt aber
nicht an der Plugin-Konfiguration, sondern am jeweiligen Repository (`db_dump_path`), siehe
[Backup-Pfad](#backup-pfad).

## Administration

Ein Modul: `src/Resources/app/administration/src/module/muwa-backup-repository`
(Settings → Extensions, Gruppe `plugins`, Routen `index` / `create` / `detail/:id/:tab?`).
Klassisches Vue-2-Style-API (`Component.register`, `Mixin.getByName('listing')`,
`this.$t(...)`) — kein Composition API, keine `sw-`Component-Overrides.
Snippets liegen modul-lokal in `snippet/de-DE.json` / `en-GB.json`.

**Dashboard-Widget.** `component/muwa-backup-status-dashboard-widget` zeigt pro aktivem Repository
den neuesten `muwa_backup_repository_stats`-Eintrag (Association mit `setLimit(1)`) auf dem
Dashboard. Eingehaengt per `Component.override('sw-dashboard-index')` in den Block
`sw_dashboard_index_content_info_grid` (in 6.6 und 6.7 identisch). Das Widget blendet sich selbst
aus, wenn `showDashboardWidget` nicht gesetzt ist oder die ACL-Rolle
`muwa_backup_repository.viewer` fehlt. Eine S3-Spalte hat es seit 0.10.0 nicht mehr (siehe S3-Dashboard-Widget).
Check-Status-Faerbung: enthaelt (case-insensitive) `no errors were found` → success,
`error|fatal|fail` → danger, sonst info. Achtung: `system:config:set` ohne `--json` speichert den
String `"true"` — das Widget akzeptiert beides.

**S3-Dashboard-Widget.** `component/muwa-s3-mirror-status-dashboard-widget` ist eine eigene Karte
"S3-Übertragungsstatus" direkt unter dem Backup-Status-Widget (gleicher Override-Block), geschaltet
ueber `showS3MirrorDashboardWidget` (unabhaengig von `showDashboardWidget`), gleiche ACL-Regel. Laedt nur
aktive Repositories mit `mirrorS3ConnectionId` (max. 25) samt `mirrorS3Connection`, neuestem Upload und
neuestem Check; letzter Erfolg wieder per kleiner Suche je Repository. Spalten: Ziel, Status (Tooltip =
`message` + Veraltet-Hinweis, wenn der neueste Upload aelter als der neueste Check ist), letzter Lauf, letzter
Erfolg, Dateien hoch/geloescht, Datenmenge. Tests: `S3MirrorDashboardWidgetAdministrationTest`.

**Ordner-Browser.** `component/muwa-backup-repository-path-field` haengt unter dem Textfeld ein
Dropdown (teleported, `position: fixed`) zur Ordnerauswahl und ruft die `filesystem`-Routes auf.
Am Dump-Pfad zeigt es live die Warnung (abgelehnt / nicht beschreibbar). Nur fuer Rolle `editor`
(`update`-Recht). Einsatz: Create-Seite — Repository Path, Restore Path, Database dump path,
Backup Paths; Detail-Tab Configuration — Restore Path, Database dump path, Backup Paths. Der
Repository Path bleibt auf der Detail-Seite gesperrt.

**Uebernahme-Modal.** `component/muwa-backup-repository-add-modal` ersetzt auf der Liste den direkten
Sprung auf `create`: Auswahl "Neues Repository" (unveraenderte Create-Seite) oder "Bestehendes
Repository uebernehmen" (Pfad + Passwort + Pflichtfeld Restore-Pfad, geprueft ueber
`Settings::getRestorePathRejection()`, Fehler `MUWA_TAKEOVER_INVALID_RESTORE_PATH`; Aufruf der `takeover`-Route, Fehlercodes werden ueber
Snippets `takeover.errors.*` angezeigt, danach Sprung in den Tab Konfiguration). Der Tab Konfiguration
zeigt ueber `Services\BackupPathSuggestions` die Pfade der vorhandenen Snapshots als Vorschlaege
(Uebernehmen traegt sie in die Backup-Pfade ein). Snippets mit Platzhaltern immer per
`$t(key, {...})`. Spalte `muwa_backup_repository_snapshots.paths` ist `LONGTEXT`
(`Migration1794614400`).

## Tests & Statische Analyse

```bash
# im Plugin-Verzeichnis
composer install
composer run-script phpstan          # PHPStan Level 6, nur src/

# aus dem Shop-Root
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin"
```

**PHPUnit 11** — lokal (`composer.json`) und in der CI (`.github/workflows/main.yml`, Schritt
"Install code-Tools"). Data-Provider nur als Attribut `#[DataProvider('...')]` mit `static`
Provider-Methode, keine `@dataProvider`-Annotationen (in 11 deprecated, ab 12 entfernt). Beide
Versionen beim Anheben immer gemeinsam aendern: Laeuft die CI mit einer aelteren PHPUnit-Version als
lokal, werden Attribute dort ignoriert und Provider-Tests scheitern mit `ArgumentCountError`.

In `sw67` laufen beide Konfigurationen, Tests nur per `ddev exec` (Host-PHP ist unbrauchbar):

```bash
# komplette Suite mit Shopware-Test-Bootstrap (tests/TestBootstrap.php)
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.xml"

# reine Unit-Tests ohne Shopware-Bootstrap (tests/UnitTestBootstrap.php), deutlich schneller
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"
```

`phpunit.unit.xml` listet die Testdateien einzeln — neue Unit-Tests dort eintragen.

`tests/` mischt echte Unit-Tests (`HelperTest`, `SettingsTest`, `BackupRunnerFactoryTest`) mit
Integrationstests, die eine laufende Shopware-Instanz und ein reales restic-Binary brauchen
(`tests/Integration/BackupRepositoryTest.php`, Helfer in `tests/TestCaseBase/`).
Integrationstests schreiben nach `var/` und legen dort echte Repositories an.

## Auffaelligkeiten

Beim Arbeiten in diesem Plugin bekannt und noch offen. **Nicht ungefragt "aufraeumen"** — das sind
Upstream-Bugs, sie gehoeren in einen Issue/PR gegen `muckiware/facility-plugin`.

**Funktionale Bugs**

1. ~~`RestoreSnapshotController::process()` dispatcht `CreateBackupMessage` statt `RestoreSnapshotMessage`~~ —
   **behoben**: der Controller dispatcht `RestoreSnapshotMessage`, jeder Handler laeuft nur noch fuer seine Message.
2. `Settings::getLastValidDateForLogEntry()` ruft `getNumberOfValidDaysInCart()` auf, ignoriert
   also `numberOfValidDaysInLogEntry` komplett.

**Code-Qualitaet**

3. `Database\TableRunner\CleanupRunner` hat einen Konstruktor mit leerem Body und ohne
   Constructor Promotion; `$this->connection` / `$this->cliOutput` in `copyTableItems()` existieren
   nur, weil die Subklassen die Properties promoten. `CartCleanupRunner` ruft `parent::__construct()`
   gar nicht auf, `LogEntryCleanupRunner` schon. Funktioniert, ist aber Zufall.
4. `Subscriber\BackupRepositorySnapshotSubscriber::onBackupRepositorySnapshotDeleted()` ist ein
   No-Op mit auskommentiertem Rumpf. Der Subscriber ist registriert und tut nichts.
5. `CompleteFileRunner`, `CompleteFilesRunner` und `Services\Backup` haben je eine eigene, identische
   `createBackupFileName()` / `prepareDbBackupFileName()` — dreifache Duplikation.
6. `BackupInterface`-Methoden `saveBackupData()`, `removeBackupData()`, `getBackupData()` sind in
   allen Runnern leere `// TODO`-Stubs; `checkBackupData()` nur im `FilesRunner` implementiert.
7. `BackupRepositoryEntity` hat Properties `entity` und `compress`, fuer die es in
   `BackupRepositoryDefinition` kein Feld gibt. `hostname` traegt zweimal `addFlags(new ApiAware())`.
8. `Services\DbTableCleanup` injiziert die konkrete `Services\Settings`, alle anderen Konsumenten
   das `SettingsInterface` — inkonsistent.
9. Kein `declare(strict_types=1)`-Verstoss, aber durchgaengig kein `final`, kein `readonly`,
   Properties `protected` statt `private`. Entspricht nicht den LightsOn-PHP-Standards — die hier
   aber auch nicht gelten (siehe Kopf). Der Stil ist konsistent, also nicht punktuell umstellen.

**Repository-Hygiene**

10. `bin/restic_0.17.3_linux_386` — ein 24 MB Linux-Binary ist versioniert (`git ls-files` bestaetigt).
11. `var/` ist per `.gitignore` komplett ausgeschlossen und reine Ablage — nie als Quelle
    behandeln. Aktuell liegt dort nur `var/cache/`; die Integrationstests aus `tests/Integration/`
    legen bei jedem Lauf echte restic-Repositories und Restore-Ergebnisse daneben. Taucht dort eine
    Migration oder sonstiger PHP-Code auf, ist das eine verirrte Kopie, kein Stand, der nach `src/`
    gehoert.

## Shopware 6.6 **und** 6.7 parallel

Das Plugin laeuft auf beiden Majors (`composer.json`: `shopware/core: ~6.6.0 || ~6.7.0`).
Es gibt **keine** getrennten Branches — jede Aenderung muss in beiden Versionen funktionieren.

### Was sich zwischen 6.6 und 6.7 unterscheidet

| Bereich | 6.6 | 6.7 |
|---|---|---|
| DBAL | 3.x | 4.x |
| `Doctrine\DBAL\Exception` | Klasse (instanziierbar) | **Interface** (`new` = Fatal Error) |
| `AbstractSchemaManager::tablesExist()` | `($names)` untypisiert | `(array $names)` |
| Vue-Compat | aktiv | `DISABLE_VUE_COMPAT` = true |
| Meteor-Komponenten | v3.11 | v4.28 |
| `sw-select-field` rendert | `sw-select-field-deprecated` | `mt-select` |

### Regeln fuer PHP

- **Nie `new \Doctrine\DBAL\Exception(...)`** — in DBAL 4 ist das ein Interface. Fuer eigene
  Fehler `Exception\TableCleanupFailedException` (oder eine andere eigene Klasse) werfen.
  `catch (\Doctrine\DBAL\Exception $e)` ist dagegen in beiden Versionen korrekt.
- `tablesExist()` immer mit Array aufrufen: `tablesExist([$tableName])`.
- Ergebnisse von `fetchNumeric()`/`fetchOne()` nie per `===` gegen String-Literale pruefen —
  DBAL 3 und 4 liefern unterschiedliche Skalartypen. Vorher casten: `(int) $row[0] === 0`.
- In Migrationen Spalten-Checks immer per `$this->columnExists($connection, $table, $column)`
  (aus `MigrationStep`, ueber `AddColumnTrait`/`ColumnExistsTrait`) machen, nie per eigener
  `INFORMATION_SCHEMA.COLUMNS`-Abfrage ohne `TABLE_SCHEMA` — die listet Spalten aller Datenbanken
  auf dem Server und kann auf einem Mehr-Schema-Host ein `ALTER TABLE` in der falschen Datenbank
  finden und das eigene faelschlich ueberspringen.

### Regeln fuer die Administration

Der Versions-Switch laeuft ueber die Shopware-Feature-Flags. In `data()` stehen
`V6_5_0_0` / `V6_6_0_0` / `V6_7_0_0`, in `created()` werden sie per `this.feature.isActive(...)`
gesetzt, und die Computeds `isV6500` / `isV6600` / `isV6700` machen sie im Template nutzbar.

Wichtig fuer das Verstaendnis der Flag-Werte (`feature.yaml` des jeweiligen Core):

- `v6.6.0.0` ist in 6.6 **und** 6.7 aktiv → `isV6600` ist in beiden Versionen `true`.
  Der `isV6600`-Zweig ist damit der gemeinsame Zweig, nicht der 6.6-exklusive.
- `v6.7.0.0` ist nur in 6.7 aktiv → `isV6700`.
- `isV6500` ist auf 6.6 und 6.7 immer `false` (toter Zweig, nur fuer 6.5 relevant).

Daraus folgt das Template-Muster: nur 6.7 → `v-if="isV6700"`, alles ausser 6.7 →
`v-if="isV6600 && !isV6700"`, beides → `v-if="isV6600"`.

Die `sw-*`-Formularfelder (`sw-text-field`, `sw-switch-field`, `sw-number-field`,
`sw-password-field`, `sw-checkbox-field`) mappen `value`/`update:value` in 6.7 intern auf die
`mt-*`-Komponenten. `v-model:value` funktioniert dort **ohne** Sonderbehandlung — nicht anfassen.

Die Ausnahme ist `sw-select-field`: ab 6.7 rendert es `mt-select`, das per `modelValue` bindet
und weitergeleitete Options-Slots ignoriert. Deshalb dort zwei Zweige (`v-model` fuer 6.7,
`v-model:value` fuer 6.6) und Optionen ausschliesslich per `:options`-Prop. `typeOptions` liefert
`id`/`name` **und** `value`/`label`, weil 6.6 die ersten und 6.7 die zweiten Keys liest.

`$listeners`, `$scopedSlots` und `slot="name"` als Attribut gibt es in 6.7 nicht mehr —
Listener kommen ueber `v-bind="$attrs"`, Slots ueber `<template #name>`.

### Bekannte Rest-Unschaerfen in 6.7 (kosmetisch, nicht funktional)

- `variant` auf `sw-button` wirkt nicht: der 6.7-Wrapper setzt `variant="secondary"` **nach**
  `v-bind="$attrs"` und ueberschreibt damit `ghost`/`danger`. Betrifft jedes Plugin gleich.
  `sw-button-process` ist nicht betroffen (nutzt `$attrs.variant || 'secondary'`).
- `small` auf `sw-icon` wird ignoriert, weil `mt-icon` die Prop nicht kennt (Icons wirken groesser).
- `$tc()` ist in 6.7 nur noch ein Alias auf `$t()` und faellt in 6.8 weg.
- `compatConfig: Shopware.compatConfig` ist in 6.7 `undefined` und damit wirkungslos —
  6.7-Core-Dateien enthalten dieselbe Zeile, also unkritisch.

Vor einem Upgrade auf 6.8 `/update-analyse` bzw. `/shopware-plugin update
muckiware/facility-plugin 6.8` laufen lassen — dort fallen die `sw-*`-Wrapper und `$tc` weg.

## Arbeitsregeln fuer dieses Plugin

- Bei neuem Backup-Typ: `Core\BackupTypes` + `Backup\BackupRunnerFactory` + Runner mit
  `BackupInterface`. Bei neuer Cleanup-Tabelle: `Core\CleanupTables` +
  `Database\TableCleanupRunnerFactory` + Runner mit `TableCleanupInterface`.
- Services **immer** in `src/Resources/config/services.xml` eintragen — es gibt kein Autowiring
  in diesem Plugin.
- Repository-Passwoerter nie loggen, nie in Messages serialisieren, nie `ApiAware` machen. Gleiches
  fuer Datenbank-Passwoerter: nie in Logs, Exception-Meldungen, API-Antworten oder Prozess-Argumenten.
  Einschraenkungen fuer `env` (`MUWA_DB_`) und `file` (`var/secrets/`) nicht aufweichen.
- S3-Secrets (`secret_access_key`): nie loggen, nie in Exceptions, API-Antworten, Messages, Historie oder
  Event-Daten (`ErrorSanitizer` nutzen). Einschraenkungen fuer `env` (`MUWA_S3_`) und `file` (`var/secrets/`)
  nicht aufweichen; `plain` gibt es nicht.
- S3-Loeschschutz (siehe Architektur) nie abschwaechen. `RepositoryMirror` ist die einzige Stelle, die
  remote loescht; keine zweite Loesch-Stelle anlegen. Neue Mirror-Felder `WriteProtected(SYSTEM_SCOPE)` halten.
- Bekannte Falle: der erste Admin-Build nach dem Hinzufuegen von Entities kann
  `bin/console cache:clear -e prod --no-warmup` brauchen.
- Neuer Datenbanktyp = Subscriber auf `ResolveDatabaseConnectionEvent` + `DatabaseDumperInterface`,
  nicht die Runner anfassen. Die Table-Cleanup-Runner bleiben auf der Systemverbindung.
- Cleanup-Code nur gegen eine Wegwerf-Datenbank testen. Der Tabellentausch selbst ist seit dem
  `RENAME TABLE`-Umbau atomar, `removeOldTableItems()` loescht aber weiterhin direkt und ohne
  Rueckfrage aus der Live-Tabelle.
- restic-Aufrufe laufen ausschliesslich ueber `muckiware/restic` (`Backup`, `Manage`, `Restore`)
  — keine eigenen `Process`/`exec`-Aufrufe hinzufuegen.
- `bin/console plugin:uninstall MuckiFacilityPlugin` droppt ohne `--keep-user-data` alle
  `muwa_*`-Tabellen (`MuckiFacilityPlugin::dropPluginTables()`), inkl. `muwa_database_connection`
  (nach `muwa_backup_repository` wegen FK), Repository-Passwoerter eingeschlossen. Vor einem
  Uninstall im Dev-Shop pruefen, ob das Flag gesetzt werden soll.
