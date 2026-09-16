# Repository-Status als eigener Tab mit persistiertem Status

Design-Dokument, 14.09.2026. Plugin `muckiware/facility-plugin`.

## Problem

Das Panel "Repository Status" liegt im Tab Snapshots der Detail-Seite
(`muwa-backup-repository-detail.html.twig`, Zeile 415–437). Gefuellt wird es von
`getBackupRepositoryStats()` (`index.js`, Zeile 527) ueber
`GET /api/_action/muwa/repository/stats/{id}`. Dahinter laufen zwei teure Operationen:
`ManageRepository::getRepositoryStatsById()` ruft `restic stats` synchron auf, und
`generateStatsOutputs()` scannt zusaetzlich per `Helper::getDirectorySize()` rekursiv das
gesamte Repository-Verzeichnis.

Beide laufen in `createdComponent()`, also bei **jedem** Oeffnen der Detail-Seite — nicht nur
beim Snapshots-Tab. Bei grossen Repositories dauert das Minuten und blockiert den Admin.

## Ziel

1. Der Status wird nicht mehr beim Seitenaufruf erhoben, sondern am Ende eines Backup-Laufs
   erhoben und persistiert.
2. Das Panel wandert aus dem Snapshots-Tab in einen eigenen Tab, positioniert hinter
   Configuration und vor Checks.
3. Der neue Tab liest ausschliesslich aus der Datenbank und laedt damit sofort.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Datenmodell | Historie, 1:n zum Repository, analog `muwa_backup_repository_checks` |
| Spaltenformat | Typisierte Spalten mit Rohwerten (Bytes/Anzahl), Formatierung erst bei der Anzeige |
| Trigger | Ende von `createBackup()`; nach Snapshot-Loeschung (asynchron); eigener CLI-Command |
| Refresh-Button | Laedt nur aus der DB neu, loest **keine** restic-Abfrage aus |
| Alte Route | `GET /api/_action/muwa/repository/stats/{id}` bleibt unveraendert (kein BC-Bruch) |
| Tab-Darstellung | Panel mit neuestem Stand + Verlaufsliste darunter |
| Aufraeum-Policy | Keine |
| Git | Feature-Branch `feat/repository-status-tab`, Merge nach `main` entscheidet der Maintainer |

Verworfene Alternativen: die Erhebung in `ManageRepository` unterzubringen (der Service ist
bereits Sammelstelle fuer Snapshots, Forget und Cleanup, und `generateStatsOutputs()` liefert
formatierte Strings statt der benoetigten Rohwerte); und den Backup-Trigger ebenfalls per
Message zu dispatchen (ein CLI-Backup auf einem System ohne laufenden Worker bliebe dann
statuslos).

## Datenmodell

Neue Tabelle `muwa_backup_repository_stats`, Migration nach dem Vorbild von
`Migration1735644494`.

| Spalte | Typ | Inhalt |
|---|---|---|
| `id` | `binary(16)` NOT NULL | Primaerschluessel |
| `backup_repository_id` | `binary(16)` NOT NULL | FK auf `muwa_backup_repository`, Required |
| `total_size` | `bigint` NULL | `total_size` aus `restic stats`, Bytes, roh |
| `total_file_count` | `bigint` NULL | `total_file_count` aus `restic stats` |
| `snapshots_count` | `int` NULL | `snapshots_count` aus `restic stats` |
| `file_system_size` | `bigint` NULL | `Helper::getDirectorySize()` auf den Repository-Pfad, Bytes |
| `check_status` | `varchar(255)` NULL | Check-Status zum Erhebungszeitpunkt |
| `created_at` | `datetime(3)` NOT NULL | |
| `updated_at` | `datetime(3)` NULL | |

Fremdschluessel und Index wie in `Migration1735644494` (`fk.backup_repository.id`).

**Alle Messwerte sind nullable.** `restic stats` liefert je nach Modus und Repository-Zustand
nicht alle Felder — `generateStatsOutputs()` prueft heute jeden Wert einzeln per
`array_key_exists()`. `getDirectorySize()` kann mit einer `FilesystemException` scheitern. Ein
Teilergebnis zu speichern ist besser, als den Datensatz zu verwerfen; die Anzeige unterscheidet
"nicht erhoben" (`null`) von "0".

**`check_status` wird bewusst denormalisiert.** Der Wert steht auch in
`muwa_backup_repository_checks`. Wuerde die Verlaufsliste ihn live von dort lesen, stuende in
jeder Zeile derselbe aktuelle Wert und der Verlauf waere falsch. Ein Datensatz beschreibt den
Zustand zu einem Zeitpunkt.

### Klassen

- `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsDefinition.php`
- `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsEntity.php`
- `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsCollection.php`

Registrierung per `shopware.entity.definition`-Tag in `services.xml` (kein Autowiring in diesem
Plugin). Alle Felder ausser dem FK tragen `ApiAware`, damit die Administration sie per DAL
lesen kann. In `BackupRepositoryDefinition` kommt ein
`OneToManyAssociationField('backupRepositoryStats', BackupRepositoryStatsDefinition::class, 'backup_repository_id')`
neben die beiden vorhandenen Assoziationen.

## Erhebung und Trigger

### `Services\RepositoryStats` (neu)

Die einzige Stelle, die einen Status erhebt und schreibt.

- `collect(string $backupRepositoryId): array` — holt `restic stats` ueber das vorhandene
  `ManageRepository::getRepositoryStatsById()`, dazu `Helper::getDirectorySize()` auf den
  Repository-Pfad und den aktuellen Check-Status ueber
  `Content\BackupRepositoryChecks::getLatestChecksByRepositoryId()`. Rueckgabe sind die fuenf
  Rohwerte, fehlende Felder als `null`. Braucht eine `@return`-Array-Shape-Annotation fuer
  PHPStan Level 6.
- `collectAndSave(string $backupRepositoryId): void` — ruft `collect()` und uebergibt an
  `Content\BackupRepositoryStats::saveNewStats()`. Vollstaendig in
  `try/catch (\Exception|FilesystemException)` mit Log-Eintrag und stillem Return.

Formatierung (`ByteUnits`) bleibt draussen: in der DB stehen Rohwerte.
`ManageRepository::generateStatsOutputs()` bleibt unveraendert fuer die alte Route.

### `Services\Content\BackupRepositoryStats` (neu)

Persistenz-Wrapper, eins zu eins nach dem Muster von `Services\Content\BackupRepositoryChecks`:

- `saveNewStats(string $backupRepositoryId, array $values): void`
- `getLatestStatsByRepositoryId(string $backupRepositoryId): ?BackupRepositoryStatsEntity`

Beide mit `Context::createDefaultContext()`.

### Trigger 1 — Backup-Ende (synchron)

In `Services\Backup::createBackup()` direkt nach `$this->manageService->saveSnapshots(...)`
(Zeile 148) ein Aufruf von `collectAndSave()`. Damit greift der Trigger fuer alle
Einstiegspunkte gleichzeitig: Admin-Button ueber `CreateBackupHandler`, CLI
`muckiware:backup:create` und jeden kuenftigen Aufrufer — alle laufen durch diese Methode.

`createBackup()` laeuft nie im HTTP-Request, sondern immer im Queue-Handler oder auf der CLI.
Ein Dispatch waere hier reiner Mehraufwand.

**Fehlerbehandlung:** Der `try/catch` sitzt im Service, nicht in `createBackup()` — an einer
Stelle, damit die Regel "ein fehlgeschlagener Status kippt kein gelungenes Backup" nicht an
mehreren Aufrufern haengt. Ohne das wuerde eine Exception am Ende von `createBackup()` den Job
als fehlgeschlagen markieren, obwohl das Backup geschrieben ist.

### Trigger 2 — Snapshot-Loeschung (asynchron)

- `MessageQueue\Message\UpdateRepositoryStatsMessage` — ein einziges Feld
  `backupRepositoryId`, implementiert `AsyncMessageInterface`.
- `MessageQueue\Handler\UpdateRepositoryStatsHandler` — ruft nur `collectAndSave()`.

`ManageController` bekommt zusaetzlich einen `MessageBusInterface` injiziert und dispatcht die
Message am Ende von `removeSnapshots()` — einmal pro Request, nicht je geloeschtem Snapshot.
Synchron ginge nicht: `removeSnapshotsByIds()` laeuft bereits im HTTP-Request und wuerde bei
grossen Repositories ins Timeout laufen.

Zwei bewusste Entscheidungen wegen bekannter Auffaelligkeiten im Plugin: Die Message erbt
**nicht** von `BackupRepositorySettings` und traegt kein Passwort — der Handler laedt das
Repository ohnehin frisch. Und sie bekommt eine eigene Typisierung, damit sie nicht in dieselbe
Falle laeuft wie `CreateBackupMessage`, auf die heute zwei Handler gleichzeitig hoeren.

Registrierung per `#[AsMessageHandler]` **und** `messenger.message_handler`-Tag in
`services.xml` — die vorhandene Doppelregistrierung ist im Plugin etabliert, hier wird nicht
abgewichen.

### Trigger 3 — CLI (synchron)

Neuer Command `muckiware:repository:stats <backupRepositoryId>` in `Commands\RepositoryStats`,
nach dem Vorbild von `Commands\ManageSnapshots`: Uuid-Validierung, `isEnabled()`-Pruefung, dann
`collectAndSave()` direkt — kein Dispatch, damit ein Cron-Lauf ohne Worker verlaesslich
funktioniert. Ausgabe der erhobenen Werte per `$output->writeln()`. Eintrag in `services.xml`
mit `console.command`-Tag.

### Was nicht triggert

Der Refresh-Button im Admin erzeugt keinen Datensatz. `restic stats` laeuft nach dieser
Aenderung nur noch in den drei genannten Faellen, nie beim Oeffnen der Detail-Seite.

## Administration

### Tab

Vierter `sw-tabs-item` mit `key="backupRepositoryStats"` zwischen dem Config- und dem
Checks-Eintrag in `muwa-backup-repository-detail.html.twig` — damit steht der Tab hinter
Configuration und vor Checks. An `module/index.js` aendert sich nichts: die Route
`detail/:id/:tab?` nimmt jeden Tab-Namen entgegen.

### Datenzugriff

- Neues Computed `backupRepositoryStatsRepository` auf `muwa_backup_repository_stats`.
- Neue Methode `fetchBackupRepositoryStats()`, gebaut wie `fetchBackupRepositoryChecks()`
  (Zeile 408): Filter auf `backupRepositoryId`, Sortierung `createdAt DESC`, Limit 10.
- Computed `latestStats` greift den ersten Eintrag ab und speist das Panel; dieselbe Collection
  speist die Verlaufsliste.

Kein `httpClient`, keine eigene Route — reine DAL-Abfrage ueber `repositoryFactory`, wie bei
Checks und Snapshots.

### Was ersetzt wird

- `getBackupRepositoryStats()` (Zeile 527) entfaellt.
- Data-Properties `requestRepositoryStats` und `stats` entfallen.
- Das Computed `statsColumns` wird durch die Spalten der Verlaufsliste ersetzt.
- Die Aufrufe in `createdComponent()` (Zeile 278) und `onRefresh()` (Zeile 313) zeigen auf
  `fetchBackupRepositoryStats()`.
- `isStatsLoading` bleibt, wird aber von einer DAL-Abfrage gesetzt statt von einem
  minutenlangen HTTP-Call.
- Die `sw-card positionIdentifier="muwaBackupRepositoryStats"` (Template-Zeile 415–437)
  verschwindet aus dem Snapshots-Tab.

### Layout

```
┌─ Repository Status ──────────────── [Refresh] ┐
│  Stand: 14.09.2026 09:12                      │
│  Gesamtgroesse Dateisystem       4.21 GB      │
│  Gesamtgroesse Repository        3.87 GB      │
│  Anzahl Snapshots                     42      │
│  Anzahl Dateien                  128.430      │
│  Check-Status                         ok      │
└───────────────────────────────────────────────┘
┌─ Verlauf ─────────────────────────────────────┐
│ Datum         FS-Groesse  Repo   Snaps   Files │
│ 14.09. 09:12    4.21 GB  3.87 GB    42  128430 │
│ 13.09. 09:11    4.18 GB  3.84 GB    41  127901 │
└───────────────────────────────────────────────┘
```

Spalten der Verlaufsliste: `createdAt`, `file_system_size`, `total_size`, `snapshots_count`,
`total_file_count`. `check_status` wird dort nicht als eigene Spalte gefuehrt — er steht im
Panel und waere in der Liste bei langen restic-Meldungen unlesbar.

### Refresh

Kein eigener Button im Panel. Die Seite hat bereits eine Sidebar mit `sw-sidebar-item` und
`onRefresh` (Template-Zeile 560); deren `v-if` wird um `tab === 'backupRepositoryStats'`
erweitert. Gleiches Bedienmuster wie in Checks und Snapshots, bedeutet im neuen Tab: Daten neu
aus der DB lesen.

### Formatierung

Die DB liefert Rohbytes, der Admin formatiert. Dafuer `Shopware.Utils.format.fileSize()` statt
des Filters `Shopware.Filter.getByName('fileSize')`: der Filter gibt bei `0` einen Leerstring
zurueck, und "0 Bytes" muss von "nicht erhoben" unterscheidbar bleiben. Eine kleine Methode im
Component kapselt das — `null` wird zu einem Platzhalter-Strich, alles andere zum formatierten
Wert. Anzahlwerte (Snapshots, Dateien) werden ueber `Number.prototype.toLocaleString()` mit dem
aktuellen Admin-Locale formatiert, nach derselben `null`-Regel.

### Empty-State

Bestandsinstallationen haben nach dem Update keinen Datensatz; der erste entsteht beim naechsten
Backup. Der Tab zeigt dann statt eines leeren Panels einen Hinweis, dass noch kein Status
erhoben wurde und wodurch er entsteht (Backup-Lauf oder `muckiware:repository:stats`).

### Snippets

Neue Keys in `snippet/de-DE.json` und `snippet/en-GB.json`: Tab-Titel unter
`muwa-backup-repository.tabs.*`, die Spalten- und Feldlabels sowie der Empty-State-Text. Die
vorhandenen Labels (`totalFileSystemSizeLabel`, `totalFileRepositorySizeLabel`,
`totalSnapshotsLabel`, `totalFilesLabel`, `CheckStatusLabel`) werden wiederverwendet — sie
bleiben von der unveraenderten alten Route referenziert.

### 6.6/6.7-Kompatibilitaet

Der neue Tab nutzt nur `sw-tabs-item`, `sw-card`, `sw-data-grid` und `sw-entity-listing` — keine
`sw-select-field`, keine Formularfelder. Die Zweig-Logik ueber `isV6600`/`isV6700` entfaellt
hier; der Tab ist in beiden Majors identisch.

## Tests

### Automatisiert

`tests/Services/RepositoryStatsTest.php` (neu), reiner Unit-Test mit Mocks, kein Kernel:

1. Vollstaendiger restic-Output wird korrekt auf die fuenf Rohwerte gemappt.
2. Fehlende Keys im JSON ergeben `null`, nicht `0`.
3. Eine `FilesystemException` aus `getDirectorySize()` laesst den Rest des Datensatzes stehen.
4. `collectAndSave()` schluckt jede Exception, loggt sie und wirft nicht weiter.

`tests/Services/BackupTest.php` (Erweiterung):

5. `createBackup()` ruft `collectAndSave()` genau einmal auf.
6. Eine Exception aus `collectAndSave()` laesst `createBackup()` normal zurueckkehren.

**Ausfuehrung:** Der Shopware-Test-Bootstrap in `sw67` bricht derzeit ab
(`Unknown column 'language.translation_auto_update'`, Stand 14.09.2026 ungeloest). Die neuen
Tests sind deshalb mock-only und laufen ueber eine Ad-hoc-`phpunit.xml` mit
`bootstrap="vendor/autoload.php"` und einzeln gelisteten `<file>`-Eintraegen. Alles, was
`tests/TestCaseBase/*` oder `tests/Integration/` braucht, ist nicht automatisiert pruefbar.

### Manuell

1. Plugin-Update einspielen, Migration pruefen.
2. Detail-Seite oeffnen: neuer Tab zeigt Empty-State, Seite laedt spuerbar schneller.
3. Backup laufen lassen: Panel und erste Verlaufszeile pruefen.
4. Snapshot loeschen, Worker laufen lassen: zweite Verlaufszeile pruefen.
5. `bin/console muckiware:repository:stats <id>` auf der CLI.

Dazu `composer run-script phpstan` (Level 6 muss gruen bleiben) und ein Administration-Build —
die gebauten Assets unter `src/Resources/public/` sind nicht versioniert.

## Migration von Bestandsdaten

Keine Backfill-Migration. Eine Migration, die fuer jedes vorhandene Repository `restic stats`
aufruft, wuerde das Plugin-Update minutenlang blockieren — also genau das Problem
reproduzieren, das diese Aenderung abschafft. Bestandsrepositories bekommen ihren ersten
Datensatz beim naechsten Backup oder CLI-Lauf; bis dahin greift der Empty-State.

## Dokumentation und Versionierung

- `README.md`: neuer Tab, neuer CLI-Command.
- Plugin-`CLAUDE.md`: Entity-Tabelle, CLI-Tabelle, MessageQueue-Abschnitt, Architektur-Diagramm.
- `CHANGELOG.md`: existiert nicht, wird mit diesem Eintrag angelegt (die Plugin-`CLAUDE.md`
  fordert sie ein).
- `composer.json`: `v0.7.0` → `v0.8.0`. Neues Feature plus neue Tabelle, kein Breaking Change,
  da die alte Route bleibt.

## Nicht Teil dieser Arbeit

Die in der Plugin-`CLAUDE.md` dokumentierten Upstream-Bugs bleiben unberuehrt und gehoeren in
eigene Issues: der falsch dispatchte `CreateBackupMessage` im `RestoreSnapshotController`, der
`SettingsInterface`-Alias auf `MuckiLogPlugin`, die Cleanup-Config-Pfade unter
`LightsOn.Library.config.*`, sowie die `LogEntryCleanupRunner`- und
`getLastValidDateForLogEntry()`-Fehler.
