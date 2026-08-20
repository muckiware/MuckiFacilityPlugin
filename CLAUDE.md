# MuckiFacilityPlugin — Claude-Kontext

Backup-, Restore- und Datenbank-Cleanup-Plugin fuer Shopware 6, basierend auf dem CLI-Tool
[restic](https://restic.readthedocs.io/). Die eigentliche restic-Ansteuerung liegt in der
Composer-Library `muckiware/restic`, dieses Plugin ist die Shopware-Integration darum herum.

**Wichtig:** Das ist ein **Fremd-Plugin** (`muckiware/facility-plugin`, MIT), kein LightsOn-Plugin.
Der Namespace ist `MuckiFacilityPlugin\`, **nicht** `LightsOn\`. Anpassungen niemals direkt im
Plugin-Verzeichnis "verstecken" — entweder Upstream-PR/Fork mit eigenem Branch, oder Composer-Patch.
Beim Update wird alles hier ueberschrieben.

## Fakten

| | |
|---|---|
| Composer-Paket | `muckiware/facility-plugin`, Version `v0.6.1` |
| Plugin-Klasse | `MuckiFacilityPlugin\MuckiFacilityPlugin` |
| Shopware im Projekt | `shopware/core` **v6.6.10.5** |
| Von Plugin unterstuetzt | Shopware 6.6.x + 6.7.x, PHP 8.2–8.4, restic >= 0.15 |
| CI-Matrix | PHP 8.2, MySQL 8.0, Shopware v6.6.9.0 (`.github/workflows/main.yml`) |
| Runtime-Deps | `muckiware/restic ^1.4`, `spatie/db-dumper ^3.7`, `gabrielelana/byte-units ^0.5` |
| Git-Stand | Branch `main`, letzter Commit `da5e97f` |

Es gibt **keine** Storefront-Integration (Controller/Templates/JS) — nur API-Routes, Admin-Modul
und CLI. Die `storefront.*.json`-Snippets enthalten `muwaSearch.suggest.*`-Keys aus einem anderen
Muckiware-Plugin und werden hier von nichts verwendet.

## Architektur

```
Admin (Vue) ──POST /api/_action/muwa/...──► Controller ──dispatch──► MessageQueue Handler
CLI (muckiware:*) ─────────────────────────► Commands ─┬─► Services\Backup ─► BackupRunnerFactory ─► BackupInterface-Runner
                                                        ├─► Services\RestoreSnapshot ─► MuckiRestic\Library\Restore
                                                        ├─► Services\ManageRepository ─► MuckiRestic\Library\Manage
                                                        └─► Services\DbTableCleanup ─► TableCleanupRunnerFactory ─► TableCleanupInterface-Runner
```

Zwei Factory-Patterns tragen die ganze Fachlogik — bei neuen Backup- oder Cleanup-Typen sind
immer drei Stellen zu aendern: Enum + Factory + Runner.

### Backup-Pfad
`BackupTypes`-Enum (`src/Core/BackupTypes.php`) → `BackupRunnerFactory` → Runner:

| Enum-Wert | Runner | Was passiert |
|---|---|---|
| `completeDatabaseSingleFile` | `Backup\Database\CompleteFileRunner` | `spatie/db-dumper` mysqldump, eine Datei |
| `completeDatabaseSeparateFiles` | `Backup\Database\CompleteFilesRunner` | eine Dump-Datei pro Tabelle |
| `files` | `Backup\Files\FilesRunner` | restic-Snapshot ueber `MuckiRestic\Library\Backup` |
| `noneDatabase` | — | DB-Dump wird uebersprungen |

`Services\Backup::createBackup()` ist die Orchestrierung: erst DB-Dump nach
`<projectDir>/var/db/backup`, dann diesen Ordner per `files`-Runner in das restic-Repository
schieben, Dump-Ordner loeschen, Files-Pfade sichern, Check-Item schreiben, Snapshots persistieren.

### Cleanup-Pfad
`CleanupTables`-Enum → `TableCleanupRunnerFactory` → `CartCleanupRunner` / `LogEntryCleanupRunner`
(beide erben von `CleanupRunner`, implementieren `TableCleanupInterface`).

Strategie: `SHOW CREATE TABLE` → Temp-Tabelle anlegen → alte Rows in Originaltabelle loeschen →
Rest in Temp kopieren → Originaltabelle `DROP` + neu anlegen → aus Temp zuruecckopieren → Temp
droppen. Das gibt anders als ein reines `DELETE` den Speicher auf der Platte wieder frei, ist aber
**destruktiv und nicht transaktional** — es gibt kein Rollback, wenn zwischen `DROP TABLE` und
`INSERT` etwas schiefgeht. Vor Tests immer Dump ziehen.

## Entities

Alle Tabellen kommen aus `src/Migration/`, DAL-Registrierung per `shopware.entity.definition`-Tag.

| Entity / Tabelle | Zweck | Relationen |
|---|---|---|
| `muwa_backup_repository` | Repository-Konfiguration (Pfad, Passwort, Hostname, Restore-Pfad, `backup_paths` als JsonField, forget-Policy) | 1:n Checks, 1:n Snapshots |
| `muwa_backup_repository_checks` | Ergebnis von `restic check` je Backup-Lauf | n:1 Repository |
| `muwa_backup_repository_snapshots` | gespiegelte restic-Snapshot-Liste (snapshotId, shortId, paths, hostname, size) | n:1 Repository |

`repository_password` traegt `removeFlag(ApiAware::class)` — das Passwort darf nie ueber die
Admin-API rausgehen. Bei Aenderungen an der Definition darauf achten.

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
| `GET /api/_action/muwa/repository/stats/{id}` | `ManageController::getRepositoryStats` | Stats-Tab |

## CLI-Commands

| Command | Klasse | Argument |
|---|---|---|
| `muckiware:backup:create` | `Commands\BackupCreate` | `backupRepositoryId` |
| `muckiware:backup:check` | `Commands\BackupCheck` | `backupRepositoryId` |
| `muckiware:backup:snapshots` | `Commands\ManageSnapshots` | `backupRepositoryId` |
| `muckiware:backup:forget` | `Commands\ManageForget` | `backupRepositoryId` |
| `muckiware:backup:restore` | `Commands\RestoreSnapshot` | `backupRepositoryId`, `snapshotId` |
| `muckiware:db:dump` | `Commands\Dump` | `backupType` (BackupTypes-Enum) |
| `muckiware:table:cleanup` | `Commands\DbTableCleanup` | `tableName` (`cart`, `log_entry`) |

Basisklasse `Commands\Commands` validiert Argumente (`Uuid::isValid`, Enum-Abgleich).
Alle Commands sind in `services.xml` mit `console.command` registriert, nicht per Autoconfigure.

## MessageQueue

`CreateBackupMessage` und `RestoreSnapshotMessage` erben beide `BackupRepositorySettings` und
implementieren `AsyncMessageInterface`. Handler: `CreateBackupHandler`, `RestoreSnapshotHandler`
(beide `#[AsMessageHandler]` **und** zusaetzlich per `messenger.message_handler`-Tag in
`services.xml` — doppelte Registrierung, siehe Auffaelligkeiten).

Passwoerter werden **nicht** in die Message serialisiert: der Handler laedt das Repository frisch
und setzt das Passwort erst dann (`setRepositoryPassword()`). Dieses Muster beibehalten.

## Konfiguration

`src/Resources/config/config.xml` definiert:
`active`, `activeDbBackup`, `compressDbBackup`, `useOwnResticPath`, `ownPathResticBinary`,
`numberOfValidDaysInCart`, `numberOfValidDaysInLogEntry`.

Zugriff ausschliesslich ueber `Services\Settings` + `Core\ConfigPath`-Enum. `Settings` liest
zusaetzlich `DATABASE_URL` via `EnvironmentHelper` und leitet den Dump-Pfad aus
`kernel->getProjectDir()` + `Defaults::DATABASE_BACKUP_PATH` ab.

## Administration

Ein Modul: `src/Resources/app/administration/src/module/muwa-backup-repository`
(Settings → Extensions, Gruppe `plugins`, Routen `index` / `create` / `detail/:id/:tab?`).
Klassisches Vue-2-Style-API (`Component.register`, `Mixin.getByName('listing')`,
`this.$t(...)`) — kein Composition API, keine `sw-`Component-Overrides.
Snippets liegen modul-lokal in `snippet/de-DE.json` / `en-GB.json`.

## Tests & Statische Analyse

```bash
# im Plugin-Verzeichnis
composer install
composer run-script phpstan          # PHPStan Level 6, nur src/

# aus dem Shop-Root
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin"
```

`tests/` mischt echte Unit-Tests (`HelperTest`, `SettingsTest`, `BackupRunnerFactoryTest`) mit
Integrationstests, die eine laufende Shopware-Instanz und ein reales restic-Binary brauchen
(`tests/Integration/BackupRepositoryTest.php`, Helfer in `tests/TestCaseBase/`).
Integrationstests schreiben nach `var/` und legen dort echte Repositories an.

## Auffaelligkeiten

Beim Arbeiten in diesem Plugin bekannt und noch offen. **Nicht ungefragt "aufraeumen"** — das sind
Upstream-Bugs, sie gehoeren in einen Issue/PR gegen `muckiware/facility-plugin`.

**Funktionale Bugs**

1. `RestoreSnapshotController::process()` dispatcht `CreateBackupMessage` statt
   `RestoreSnapshotMessage`. Da `CreateBackupHandler` und `RestoreSnapshotHandler` beide auf
   `CreateBackupMessage` typisiert sind, laufen bei **jedem** Backup und **jedem** Restore ueber
   die Admin-Oberflaeche beide Handler. `RestoreSnapshotMessage` wird nirgends verwendet
   (`grep` bestaetigt: nur die Klassendefinition).
2. `LogEntryCleanupRunner::removeOldTableItems()` loescht im else-Zweig (wenn `log_entry` keine
   Spalte `updated_at` hat) aus `cart` statt aus `log_entry` — Copy-Paste-Fehler mit Datenverlust
   in der falschen Tabelle.
3. `Settings::getLastValidDateForLogEntry()` ruft `getNumberOfValidDaysInCart()` auf, ignoriert
   also `numberOfValidDaysInLogEntry` komplett.
4. `Core\ConfigPath::CONFIG_PATH_NUMBER_OF_VALID_DAYS_IN_CART` / `..._IN_LOG_ENTRY` zeigen auf
   `LightsOn.Library.config.*` statt `MuckiFacilityPlugin.config.*`. Beide Cleanup-Einstellungen
   greifen deshalb nie, es gilt immer der Fallback von 30 Tagen.
5. `services.xml`: `MuckiFacilityPlugin\Services\SettingsInterface` ist Alias auf
   `MuckiLogPlugin\Services\Settings` — falscher Namespace, anderes Plugin. Faellt derzeit nicht
   auf, weil alle Konsumenten die konkrete `Services\Settings` per Argument bekommen und Symfony
   den ungenutzten privaten Alias wegoptimiert. Sobald jemand per Autowiring auf das Interface
   geht, bricht der Container.

**Code-Qualitaet**

6. `Database\TableRunner\CleanupRunner` hat einen Konstruktor mit leerem Body und ohne
   Constructor Promotion; `$this->connection` / `$this->cliOutput` in `copyTableItems()` existieren
   nur, weil die Subklassen die Properties promoten. `CartCleanupRunner` ruft `parent::__construct()`
   gar nicht auf, `LogEntryCleanupRunner` schon. Funktioniert, ist aber Zufall.
7. `Subscriber\BackupRepositorySnapshotSubscriber::onBackupRepositorySnapshotDeleted()` ist ein
   No-Op mit auskommentiertem Rumpf. Der Subscriber ist registriert und tut nichts.
8. `CompleteFileRunner`, `CompleteFilesRunner` und `Services\Backup` haben je eine eigene, identische
   `createBackupFileName()` / `prepareDbBackupFileName()` — dreifache Duplikation.
9. `BackupInterface`-Methoden `saveBackupData()`, `removeBackupData()`, `getBackupData()` sind in
   allen Runnern leere `// TODO`-Stubs; `checkBackupData()` nur im `FilesRunner` implementiert.
10. `BackupRepositoryEntity` hat Properties `entity` und `compress`, fuer die es in
    `BackupRepositoryDefinition` kein Feld gibt. `hostname` traegt zweimal `addFlags(new ApiAware())`.
11. `Services\DbTableCleanup` injiziert die konkrete `Services\Settings`, alle anderen Konsumenten
    das `SettingsInterface` — inkonsistent.
12. Kein `declare(strict_types=1)`-Verstoss, aber durchgaengig kein `final`, kein `readonly`,
    Properties `protected` statt `private`. Entspricht nicht den LightsOn-PHP-Standards; bei
    Fremdcode ist das hinzunehmen.

**Repository-Hygiene**

13. `bin/restic_0.17.3_linux_386` — ein 24 MB Linux-Binary ist versioniert (`git ls-files` bestaetigt).
14. `var/` ist gitignored, enthaelt aber lokale Artefakte aus Integrationstests: echte
    restic-Repositories (`var/repository/`), Restore-Ergebnisse (`var/restore/`), PHPStan-Cache und
    eine Kopie der Migrationen. In `var/Migration/` liegt zusaetzlich eine **neuere** Migration
    `Migration1771602076` (Spalte `db_dump_path` in `muwa_backup_repository`), die es in `src/`
    noch nicht gibt — nicht als Quelle behandeln, das ist Ablage.
15. Aktueller Arbeitsstand ist unsauber: `src/Services/ManageRepository.php` modifiziert,
    und `src/Core/Content/Media/` ist **untracked** — CMS-Media-Resolver
    (`DefaultMediaResolver`, `ImageCmsElementResolver`, Slider/Gallery-Resolver), die thematisch
    nicht zu einem Backup-Plugin gehoeren und in keiner `services.xml` registriert sind. Vor eigenen
    Aenderungen klaeren, ob das weg kann.

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
- Repository-Passwoerter nie loggen, nie in Messages serialisieren, nie `ApiAware` machen.
- Cleanup-Code nur gegen eine Wegwerf-Datenbank testen (DROP TABLE ohne Transaktion).
- restic-Aufrufe laufen ausschliesslich ueber `muckiware/restic` (`Backup`, `Manage`, `Restore`)
  — keine eigenen `Process`/`exec`-Aufrufe hinzufuegen.
