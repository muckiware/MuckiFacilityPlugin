# Uebernahme bestehender restic-Repositories — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein vorhandenes lokales restic-Repository laesst sich ueber das Modal am Button
"Repository anlegen" mit Pfad und Passwort in die Plugin-Verwaltung uebernehmen, samt Snapshots,
nachgelagertem Struktur-Check, Statistik und Pfad-Vorschlaegen in der Konfiguration.

**Architecture:** Zuerst bekommt die Library `muckiware/restic` drei rueckwaertskompatible
Optionen (Check ohne `--read-data`, `--no-lock` fuer `snapshots`, tolerante `Snapshot`-Klasse) und
ein Release. Im Plugin erledigt ein neuer Service `RepositoryTakeover` die Pruef- und
Anlege-Reihenfolge, ein Controller stellt Uebernahme- und Vorschlags-Route bereit, eine neue
Message stoesst Check + Statistik an. In der Administration ersetzt ein Zwei-Schritt-Modal den
direkten Link auf die Create-Seite; die Detail-Seite zeigt Pfad-Vorschlaege.

**Tech Stack:** PHP 8.2–8.4, Shopware 6.6 + 6.7 (DAL, Messenger, Admin Vue im Options-API-Stil),
`muckiware/restic` (Symfony Process, JsonMapper), PHPUnit 11 im Plugin, PHPUnit 10 in der Library.

**Spec:** `docs/superpowers/specs/2026-11-12-repository-takeover-design.md` (freigegeben
14.11.2026). Offene-Fragen-Protokoll: `docs/superpowers/specs/2026-11-12-repository-takeover-open-questions.md`.

## Global Constraints

- **Git:** Gearbeitet wird ausschliesslich auf dem aktuell ausgecheckten Branch, im Plugin
  (`custom/static-plugins/MuckiFacilityPlugin`, derzeit `main`) wie in der Library
  (`~/shopdev/restic`, derzeit `main`). Kein Branch-Wechsel, kein neuer Branch, **kein Commit,
  kein Tag, kein Push** — das macht der Maintainer selbst nach Sichtung des Diffs.
- Namespace Plugin `MuckiFacilityPlugin\`, Library `MuckiRestic\`. Nicht LightsOn: kein `final`/
  `readonly`-Zwang, Properties `protected`, Stil der umliegenden Dateien uebernehmen.
- `declare(strict_types=1);` und der uebliche Datei-Kopfkommentar in jeder neuen PHP-Datei.
- Plugin-Services nur per `src/Resources/config/services.xml`, kein Autowiring. Handler zusaetzlich
  mit `<tag name="messenger.message_handler"/>` wie `UpdateRepositoryStatsHandler`.
- restic nur ueber `muckiware/restic`, kein eigenes `Process`/`exec`.
- Das Repository-Passwort steht nie in Logs, Exception-Meldungen, API-Antworten, Messages oder
  Prozess-Argumenten (restic bekommt es per `RESTIC_PASSWORD`).
- Die Uebernahme ruft kein `init`, `unlock`, `prune`, `forget` und kein Backup auf.
- Muss in Shopware 6.6 **und** 6.7 laufen (Feature-Flag-Muster `isV6600`/`isV6700` aus der
  Plugin-`CLAUDE.md`). In Snippet-Aufrufen mit Platzhaltern `$t(key, {...})` statt
  `$tc(key, 0, {...})` (verliert in 6.7 die Werte).
- Neue Texte in `snippet/de-DE.json` **und** `snippet/en-GB.json` des Moduls.
- Plugin-Version `v0.10.0`, Abschnitt `0.10.0` in `CHANGELOG.md`; Library-Version `v1.6.0`.
- Plugin-Tests: `ddev mutagen sync && ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"` (aus `~/shopdev/sw6/sw67`).
- Library-Tests: aus `~/shopdev/restic` `ddev exec ./vendor/bin/phpunit --configuration=phpunit_without_integration.xml`
  (erwartet: ein vorbestehender Fehler in `BackupTest::testCheckCreateBackup` auf frischem Checkout,
  siehe Library-`CLAUDE.md`).

## Review Focus

1. **Repository-Pfad mit Schraegstrich am Ende oder doppelten Schraegstrichen** (`/srv/repo/`,
   `/srv//repo`): Dublettenpruefung und Name muessen dasselbe ergeben wie bei `/srv/repo` — Test in Task 6.
2. **Snapshot mit `time` in UTC (`...Z`) neben Snapshots mit Offset**: Der juengste Snapshot fuer
   den Hostnamen muss nach echtem Zeitpunkt bestimmt werden, nicht nach String-Vergleich — Test in Task 6.
3. **Repository mit null Snapshots**: Uebernahme gelingt, `snapshotsCount = 0`, Hostname = Plugin-Default,
   Pfad-Vorschlaege leer ohne Fehler — Tests in Task 6 und Task 8.
4. **Snapshot-Pfad mit Komma** (`/srv/a,b`): Vorschlaege zeigen genau diesen einen Pfad, nicht zwei —
   Test in Task 8.
5. **Passwort mit Sonderzeichen/Leerzeichen am Rand** (`" pa ss "`): wird unveraendert an restic und in
   die Speicherform gegeben, nicht getrimmt; nur ein komplett leeres/whitespace-only Passwort wird
   abgelehnt — Test in Task 6.

---

## Teil A — Library `muckiware/restic` (`~/shopdev/restic`)

### Task 1: Check ohne `--read-data` und `snapshots --no-lock`

**Files:**
- Modify: `src/Library/Configuration.php` (neue Properties + Getter/Setter neben `$jsonOutput`)
- Modify: `src/Library/CommandLine/Commands/Check.php`, `CheckAmazonS3.php`
- Modify: `src/Library/CommandLine/Commands/Snapshots.php`, `SnapshotsAmazonS3.php`
- Test: `tests/Library/CommandLine/CommandBuilderTest.php`

**Interfaces:**
- Produces: `Configuration::isCheckReadData(): bool` (Default `true`),
  `Configuration::setCheckReadData(bool $checkReadData): void`,
  `Configuration::isNoLock(): bool` (Default `false`), `Configuration::setNoLock(bool $noLock): void`.
  Weil `Manage`/`Backup` ihre Properties per Reflection in die Factory kopieren, wirken die
  Setter auf den Fassaden-Objekten ohne weitere Aenderung.

- [ ] **Step 1: Failing Tests schreiben** (in `CommandBuilderTest`, `config()` wiederverwenden)

```php
public function testCheckReadsDataByDefault(): void
{
    self::assertContains('--read-data', Check::getCommandLine($this->config()));
}

public function testCheckWithoutReadData(): void
{
    $configuration = $this->config();
    $configuration->setCheckReadData(false);
    self::assertNotContains('--read-data', Check::getCommandLine($configuration));
    self::assertNotContains('--read-data', CheckAmazonS3::getCommandLine($configuration));
}

public function testSnapshotsWithoutLockIsOptIn(): void
{
    $configuration = $this->config();
    self::assertNotContains('--no-lock', Snapshots::getCommandLine($configuration));
    $configuration->setNoLock(true);
    self::assertContains('--no-lock', Snapshots::getCommandLine($configuration));
    self::assertContains('--no-lock', SnapshotsAmazonS3::getCommandLine($configuration));
}
```

(Fuer `CheckAmazonS3`/`SnapshotsAmazonS3` die in der Testklasse schon vorhandene S3-Konfiguration
nutzen, falls `config()` dafuer nicht reicht.)

- [ ] **Step 2: Tests laufen lassen, muessen scheitern** — `ddev exec ./vendor/bin/phpunit --configuration=phpunit_without_integration.xml --filter 'CheckReadsData|CheckWithoutReadData|SnapshotsWithoutLock'` → FAIL (`Call to undefined method ...setCheckReadData`).
- [ ] **Step 3: Implementieren.** Properties `protected bool $checkReadData = true;` und
  `protected bool $noLock = false;` in `Configuration`. `Check`/`CheckAmazonS3`: `--read-data` nur
  anhaengen, wenn `isCheckReadData()`. `Snapshots`/`SnapshotsAmazonS3`: `--no-lock` direkt nach
  `snapshots` anhaengen, wenn `isNoLock()`.
- [ ] **Step 4: Tests laufen lassen** → PASS (bis auf den bekannten Fehler in `BackupTest::testCheckCreateBackup`).

### Task 2: Tolerante `Snapshot`-Klasse

**Files:**
- Modify: `src/Entity/Result/ResticResponse/Snapshot.php`
- Test: `tests/Entity/SnapshotMappingTest.php` (neu; Namespace `MuckiRestic\Test\Entity`)

**Interfaces:**
- Produces: `Snapshot::getSummary(): ?Summary`, `Snapshot::hasSummary(): bool`. Alle anderen
  String-Properties ausser `id`, `short_id`, `time` bekommen Default `''`, `paths` Default `[]`,
  `summary` wird `protected ?Summary $summary = null`.

- [ ] **Step 1: Failing Test schreiben**

```php
public function testSnapshotWithoutSummaryAndHostnameMaps(): void
{
    $json = json_decode('{"time":"2023-01-05T02:00:00.123456789Z","tree":"t","paths":["/srv/a"],'
        .'"id":"1234567890abcdef","short_id":"12345678"}');
    $snapshot = (new \JsonMapper())->map($json, new Snapshot());

    self::assertFalse($snapshot->hasSummary());
    self::assertNull($snapshot->getSummary());
    self::assertSame('', $snapshot->getHostname());
    self::assertSame('', $snapshot->getParent());
    self::assertSame(['/srv/a'], $snapshot->getPaths());
}

public function testSnapshotWithoutPathsMaps(): void
{
    $json = json_decode('{"time":"2023-01-05T02:00:00Z","id":"1234567890abcdef","short_id":"12345678"}');
    $snapshot = (new \JsonMapper())->map($json, new Snapshot());

    self::assertSame([], $snapshot->getPaths());
}
```

- [ ] **Step 2: Test laufen lassen** — `ddev exec ./vendor/bin/phpunit --configuration=phpunit_without_integration.xml tests/Entity/SnapshotMappingTest.php` → FAIL (`must not be accessed before initialization`). Falls `tests/Entity` nicht von `phpunit_without_integration.xml` erfasst wird, den Ordner dort ergaenzen.
- [ ] **Step 3: Defaults und `hasSummary()` in `Snapshot` einbauen.**
- [ ] **Step 4: Test laufen lassen** → PASS; danach `ddev composer run-script phpstan` → nur die 2 vorbestehenden Fehler in `Backup/AmazonS3.php`.

### Task 3: Library-Version und Changelog

**Files:**
- Modify: `composer.json` (`"version": "v1.6.0"`)
- Modify: `CHANGELOG.md` (neuer Abschnitt `## [1.6.0] - <Datum>` oberhalb von `1.5.0`)
- Modify: `README.md` (kurz: `setCheckReadData(false)`, `setNoLock(true)`), falls die README die Optionen auflistet

- [ ] **Step 1: Changelog schreiben.** Unter `### Added`: `setCheckReadData()`, `setNoLock()`.
  Unter `### Changed`: `Snapshot::getSummary()` liefert `?Summary` und fehlende Felder aelterer
  restic-Versionen fuehren nicht mehr zu einem `\Error`; neuer `hasSummary()`.
- [ ] **Step 2: Volle Library-Suite laufen lassen** — `ddev exec ./vendor/bin/phpunit --configuration=phpunit.xml` → erwartet wie in der Library-`CLAUDE.md`: nur die 4 `IntegrationAwsS3Test`-Fehler.

### ⏸ Haltepunkt: Library-Release durch den Maintainer

Hier anhalten und dem Maintainer melden: Library-Diff liegt bereit. Er committet, taggt `v1.6.0`,
pusht und wartet, bis Packagist die Version kennt. Erst danach weiter mit Task 5. Task 4 haengt
nicht an der Library und darf vorher laufen.

---

## Teil B — Plugin `MuckiFacilityPlugin`

### Task 4: Robuster Snapshot-Import, Zeitstempel, Spalte `paths`

**Files:**
- Modify: `src/Services/Helper.php:145-155` (`createDateTimeFromString`)
- Modify: `src/Services/Content/BackupFileSnapshotsRepository.php:51-75` (`createNewSnapshots`)
- Modify: `src/Core/Content/BackupRepository/Snapshots/BackupRepositorySnapshotsDefinition.php:58`
- Create: `src/Migration/Migration1794614400.php`
- Test: `tests/Services/HelperTest.php`, `tests/Services/Content/BackupFileSnapshotsRepositoryTest.php` (neu),
  `tests/Core/Content/BackupRepositorySnapshotsDefinitionTest.php` (neu, Stil wie `BackupRepositoryDefinitionTest`)

**Interfaces:**
- Produces: `BackupFileSnapshotsRepository::createNewSnapshots(string $backupRepositoryId, array $snapshots): int`
  (Anzahl geschriebener Zeilen; wirft weiter, wenn der DAL-Write scheitert). Bestehender Aufrufer
  `ManageRepository::saveSnapshots()` ignoriert den Rueckgabewert.
- Produces: `Helper::createDateTimeFromString()` liest auch `Z` und 7–9 Nachkommastellen.

- [ ] **Step 1: Failing Tests schreiben**

```php
// HelperTest
public function testUtcTimestampWithNanosecondsIsParsed(): void
{
    $date = (new Helper())->createDateTimeFromString('2024-03-01T02:00:00.123456789Z');
    self::assertNotNull($date);
    self::assertSame('2024-03-01 02:00:00 +00:00', $date->format('Y-m-d H:i:s P'));
}

public function testOffsetTimestampWithNanosecondsStillParsed(): void
{
    $date = (new Helper())->createDateTimeFromString('2024-03-01T02:00:00.123456789+01:00');
    self::assertSame('2024-03-01 02:00:00 +01:00', $date?->format('Y-m-d H:i:s P'));
}
```

`BackupFileSnapshotsRepositoryTest` (EntityRepository gemockt, `create()` faengt den Payload ab;
`PluginHelper` echt oder mit echtem `createDateTimeFromString`):

- `testSnapshotWithoutSummaryIsImportedWithoutSize`: `summary` fehlt → `size === null`,
  `totalFilesProcessed === null`, Rueckgabe `1`.
- `testMissingHostnamePathsAndShortIdDoNotAbort`: nur `id` + `time` → `hostname === ''`,
  `paths === ''`, `snapshotShortId === substr(id, 0, 8)`.
- `testSnapshotWithoutIdIsSkippedAndLogged`: `[valid, ['time' => ...], valid]` → zwei Zeilen im
  Payload, Logger `warning` genau einmal, Rueckgabe `2`.
- `testLongPathListIsKeptCompletely`: 20 Pfade à 60 Zeichen → `paths` enthaelt alle, per `,` verbunden.
- `testEmptyListWritesNothing`: `[]` → `create()` nie aufgerufen, Rueckgabe `0`.

`BackupRepositorySnapshotsDefinitionTest::testPathsIsLongText`: Feld `paths` ist `LongTextField`.

- [ ] **Step 2: Tests laufen lassen** → FAIL.
- [ ] **Step 3: Implementieren.**
  - Regex in `createDateTimeFromString`: Nachkommastellen vor `Z|+|-` auf `.000` kuerzen (`/(\.\d+)(Z|\+|\-)/`).
  - `createNewSnapshots`: Payload je Snapshot in `protected function createSnapshotPayload(string $backupRepositoryId, array $snapshot): ?array`
    (gibt `null` ohne `id` zurueck → `logger->warning`, Snapshot-ID-freie Meldung). `size` nur bei
    vorhandenem `summary.total_bytes_processed`, sonst `null`; `totalFilesProcessed` analog `null`.
  - Definition: `paths` → `LongTextField` (ApiAware bleibt).
  - Migration `Migration1794614400`: wenn Spalte `muwa_backup_repository_snapshots.paths` existiert
    und `DATA_TYPE` (per `INFORMATION_SCHEMA` mit `TABLE_SCHEMA = DATABASE()`, Muster
    `Migration1793808551`) nicht `text`/`longtext` ist → `ALTER TABLE ... MODIFY COLUMN `paths` LONGTEXT NULL DEFAULT NULL`.
    Idempotent, `updateDestructive` leer.
- [ ] **Step 4: Tests laufen lassen** → PASS.
- [ ] **Step 5: Migration im Shop pruefen** — `ddev exec bin/console database:migrate MuckiFacilityPlugin --all` und
  `ddev exec "mysql -e \"SHOW COLUMNS FROM muwa_backup_repository_snapshots LIKE 'paths'\" db"` → Typ `longtext`.
  Zweiter Lauf von `database:migrate` → kein Fehler.

### Task 5: Library im Plugin anheben, restic-Zugriffe in `ManageRepository`

Erst nach dem Haltepunkt (Library `v1.6.0` auf Packagist).

**Files:**
- Modify: `composer.json` des Plugins (`"muckiware/restic": "^1.6"`, `"version": "v0.10.0"`)
- Modify: `src/Services/ManageRepository.php`
- Test: `tests/Services/ManageRepositoryTest.php` (neu)

**Interfaces:**
- Consumes: `Manage::setNoLock()`, `Backup::setCheckReadData()` aus Task 1.
- Produces:
  - `ManageRepository::readSnapshots(string $repositoryPath, string $repositoryPassword): array`
    — `restic snapshots --json --no-lock`, gibt das dekodierte Array zurueck. Wirft bei
    Prozessfehler die Exception der Library weiter und bei Ausgabe, die kein JSON-Array ist,
    `\RuntimeException('restic snapshots returned no valid JSON list')`. Schluckt nichts.
  - `ManageRepository::checkRepositoryStructure(string $backupRepositoryId): string` — laedt das
    Repository, `restic check` ohne `--read-data` ueber `createBackupClient()`, letzter Status aus
    `PluginHelper::getCheckResults()`, speichert ihn per `BackupRepositoryChecks::saveNewCheck()`
    und gibt ihn zurueck. Wirft `\RuntimeException`, wenn das Repository fehlt oder kein Status
    lesbar ist; Prozessfehler gehen weiter. Kein `unlock`/`prune`.
  - `protected function createBackupClient(): \MuckiRestic\Library\Backup` (analog `createManageClient()`).
  - Beide Methoden setzen den eigenen Binary-Pfad wie die vorhandenen Methoden.

- [ ] **Step 1: Library einspielen** — `composer.json` anpassen, dann aus `~/shopdev/sw6/sw67`
  `ddev composer update muckiware/restic muckiware/facility-plugin` und
  `ddev composer show muckiware/restic | grep versions` → `v1.6.0`.
- [ ] **Step 2: Failing Tests schreiben** (Subklasse von `ManageRepository` ueberschreibt
  `createManageClient()`/`createBackupClient()` und liefert Mocks von `Manage`/`Backup`):
  - `testReadSnapshotsUsesNoLockAndDecodes`: Mock erwartet `setNoLock(true)`, `setJsonOutput(true)`,
    `setRepositoryPath('/srv/repo')`, `setRepositoryPassword('pw')`; `getSnapshots()` liefert
    `ResultEntity` mit Output `'[{"id":"a"}]'` → Rueckgabe `[['id' => 'a']]`.
  - `testReadSnapshotsRethrowsProcessFailure`: `getSnapshots()` wirft `\RuntimeException('boom')` →
    Test erwartet dieselbe Exception, Logger `error` nie aufgerufen.
  - `testReadSnapshotsRejectsNonList`: Output `'null'` bzw. `'{"a":1}'` → `\RuntimeException`.
  - `testCheckRepositoryStructureSkipsReadDataAndSavesStatus`: Backup-Mock erwartet
    `setCheckReadData(false)`, `checkBackup()` liefert Output; `PluginHelper::getCheckResults`
    liefert `['no errors were found']` → `saveNewCheck($id, 'no errors were found')`, Rueckgabe gleich.
  - `testCheckRepositoryStructureThrowsForUnknownRepository`: `getBackupRepositoryById` → `null` → `\RuntimeException`, kein Client erzeugt.
- [ ] **Step 3: Tests laufen lassen** → FAIL.
- [ ] **Step 4: Implementieren.**
- [ ] **Step 5: Tests laufen lassen** → PASS.

### Task 6: Service `RepositoryTakeover`

**Files:**
- Create: `src/Exception/RepositoryTakeoverException.php`
- Create: `src/Services/RepositoryTakeover.php`
- Create: `src/MessageQueue/Message/TakeoverRepositoryCheckMessage.php` (nur die Klasse; Handler in Task 7)
- Modify: `src/Services/DirectoryBrowser.php` (neue public Methode)
- Modify: `src/Services/Content/BackupRepository.php` (zwei neue Methoden)
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Services/RepositoryTakeoverTest.php` (neu), `tests/Services/DirectoryBrowserTest.php`

**Interfaces:**
- Consumes: `ManageRepository::readSnapshots()` (Task 5), `BackupFileSnapshotsRepository::createNewSnapshots(): int` (Task 4).
- Produces:
  - `RepositoryTakeoverException extends \RuntimeException` mit
    `__construct(string $errorCode, string $message, array $meta = [], ?\Throwable $previous = null)`,
    `getErrorCode(): string`, `getMeta(): array<string, string>` und den Konstanten
    `INVALID_INPUT = 'MUWA_TAKEOVER_INVALID_INPUT'`, `PATH_NOT_FOUND = 'MUWA_TAKEOVER_PATH_NOT_FOUND'`,
    `NOT_A_REPOSITORY = 'MUWA_TAKEOVER_NOT_A_REPOSITORY'`, `NOT_WRITABLE = 'MUWA_TAKEOVER_NOT_WRITABLE'`
    (meta `user`, `path`), `DUPLICATE = 'MUWA_TAKEOVER_DUPLICATE'` (meta `name`),
    `PASSWORD_NOT_STORABLE = 'MUWA_TAKEOVER_PASSWORD_NOT_STORABLE'`,
    `ACCESS_FAILED = 'MUWA_TAKEOVER_ACCESS_FAILED'`, `IMPORT_FAILED = 'MUWA_TAKEOVER_IMPORT_FAILED'`.
  - `DirectoryBrowser::normalizeRepositoryPath(string $path): string` — `validate()` + `normalize()`,
    wirft `InvalidBrowsePathException`.
  - `BackupRepository::getBackupRepositoryByPath(string $repositoryPath): ?BackupRepositoryEntity`
    (`equalsAny('repositoryPath', [$path, $path . '/'])`, erster Treffer).
  - `BackupRepository::removeBackupRepository(string $backupRepositoryId): void` (System-Scope, wie `createBackupRepository`).
  - `TakeoverRepositoryCheckMessage implements AsyncMessageInterface` mit
    `__construct(protected string $backupRepositoryId)` und `getBackupRepositoryId(): string` (Muster `UpdateRepositoryStatsMessage`).
  - `RepositoryTakeover::takeover(string $backupRepositoryId, string $repositoryPath, string $repositoryPassword): int`
    — gibt die Anzahl importierter Snapshots zurueck, wirft sonst `RepositoryTakeoverException`.
    Konstruktor: `LoggerInterface`, `BackupRepository`, `BackupFileSnapshotsRepository`,
    `ManageRepository`, `DirectoryBrowser`, `PluginHelper`, `MessageBusInterface`.
  - Konstanten: `REQUIRED_WRITABLE_DIRECTORIES = ['locks', 'snapshots', 'index', 'data']`,
    `FALLBACK_NAME = 'restic-repository'`.

Reihenfolge in `takeover()` (Spec "Uebernahme-Route"):
1. `id` gueltige UUID, Pfad nicht leer (`trim`), Passwort nicht leer (`trim($pw) === ''`, das
   Passwort selbst wird **nicht** getrimmt) → sonst `INVALID_INPUT`. Pfad per
   `normalizeRepositoryPath()`, `InvalidBrowsePathException` → `INVALID_INPUT`. Name =
   `basename($normalized)`, leer → `FALLBACK_NAME`.
2. `is_dir` + `is_readable` → sonst `PATH_NOT_FOUND`; keine Datei `config` → `NOT_A_REPOSITORY`;
   Repository-Ordner und jeder vorhandene Ordner aus `REQUIRED_WRITABLE_DIRECTORIES` per
   `is_writable` → erster Fehlschlag `NOT_WRITABLE` mit `user` (`posix_getpwuid(posix_geteuid())['name']`,
   falls `posix_*` fehlt `get_current_user()`) und `path`. Keine Testdateien anlegen.
3. `getBackupRepositoryByPath()` → Treffer `DUPLICATE` mit `name`.
4. `prepareStoredPassword($pw, PasswordSource::ENCRYPTED)`; jede Exception → `PASSWORD_NOT_STORABLE`
   (Meldung ohne Passwort).
5. `readSnapshots()`; jede Exception → `ACCESS_FAILED`, restic-Meldung als `$message`.
6. DTO `BackupRepositorySettings` vollstaendig belegen wie `InitBackupRepositoryController::createRepositoryInitInputs()`,
   mit: `active=false`, `type = BackupTypes::NONE_DATABASE->value`,
   `restorePath=''`, `dbDumpPath=null`, Forget-Werte 0, Pre-/Post-Command-Typ `none` mit leeren
   Befehlen und `failOnError=true`, `backupPaths=[]`, `databaseConnectionId=null`. Hostname: der
   nicht leere `hostname` des Snapshots mit dem spaetesten Zeitpunkt (per
   `PluginHelper::createDateTimeFromString()`, Snapshots ohne lesbares `time` zaehlen nicht); sonst
   `setHostName()` nicht aufrufen (DTO-Default `muwa-facility-client`). `createBackupRepository()`.
7. `createNewSnapshots()`; Exception → `removeBackupRepository()`, dann `IMPORT_FAILED`.
8. `messageBus->dispatch(new TakeoverRepositoryCheckMessage($id))`. Scheitert nur das Dispatchen,
   wird geloggt, die Uebernahme gilt trotzdem.

- [ ] **Step 1: Failing Tests schreiben.** Echte Temp-Verzeichnisse (`sys_get_temp_dir()`, in
  `tearDown` aufraeumen) mit `config`-Datei und den vier Unterordnern; restliche Abhaengigkeiten gemockt.
  - `testRejectionsWriteNothing` (DataProvider, `static`): ungueltige ID, leerer Pfad, Passwort `'   '`,
    Pfad existiert nicht, Ordner ohne `config`, `locks` per `chmod 0555` nicht schreibbar, Dublette,
    `prepareStoredPassword` wirft, `readSnapshots` wirft → jeweils erwarteter Code, und
    `createBackupRepository`, `createNewSnapshots`, `dispatch` nie aufgerufen. (Den chmod-Fall mit
    `markTestSkipped`, wenn `posix_geteuid() === 0`.)
  - `testNotWritableNamesUserAndPath`: meta `path` endet auf `/locks`, `user` nicht leer.
  - `testSuccessCreatesInactiveRecordWithDefaults`: abgefangener DTO hat `isActive() === false`,
    `getName() === 'myrepo'` fuer Pfad `<tmp>/myrepo/`, `getRepositoryPath()` ohne Schraegstrich am Ende,
    `getBackupPaths() === []`, Forget-Werte 0; Rueckgabe = Anzahl Snapshots; `dispatch` einmal mit
    `TakeoverRepositoryCheckMessage` dieser ID; `prepareStoredPassword` mit `' pa ss '` unveraendert.
  - `testHostnameComesFromLatestSnapshotByTime`: Snapshots `2024-03-01T23:30:00Z` (host `a`) und
    `2024-03-02T00:10:00+02:00` (host `b`, real frueher) → `getHostName() === 'a'`.
  - `testEmptyRepositoryUsesDefaultHostname`: `readSnapshots` → `[]` → Rueckgabe 0,
    `getHostName() === Defaults::DEFAULT_REPOSITORY_HOST_NAME`.
  - `testDuplicateMatchesTrailingSlashVariant`: Eingabe `<tmp>/repo//` → `getBackupRepositoryByPath`
    mit `<tmp>/repo` aufgerufen.
  - `testImportFailureRemovesRecord`: `createNewSnapshots` wirft → `removeBackupRepository($id)`, Code `IMPORT_FAILED`.
  - `testExceptionMessagesNeverContainPassword`: alle Ablehnungsfaelle mit Passwort `'S3cr3t!'` →
    `getMessage()` und `json_encode(getMeta())` enthalten es nicht.
  - `DirectoryBrowserTest::testNormalizeRepositoryPath`: `'/srv//repo/'` → `'/srv/repo'`, `'../x'` wirft.
- [ ] **Step 2: Tests laufen lassen** → FAIL.
- [ ] **Step 3: Implementieren**, Service in `services.xml` eintragen (Argumente in Konstruktor-Reihenfolge,
  `MessageBusInterface` als `messenger.default_bus`).
- [ ] **Step 4: Tests laufen lassen** → PASS.

### Task 7: Hintergrundlauf (Check + Statistik + Benachrichtigung)

**Files:**
- Create: `src/MessageQueue/Handler/TakeoverRepositoryCheckHandler.php`
- Modify: `src/Resources/config/services.xml`
- Modify: `snippet/de-DE.json`, `snippet/en-GB.json` (Block `notification`)
- Test: `tests/MessageQueue/Handler/TakeoverRepositoryCheckHandlerTest.php`

**Interfaces:**
- Consumes: `TakeoverRepositoryCheckMessage` (Task 6), `ManageRepository::checkRepositoryStructure()` (Task 5),
  `RepositoryStats::collectAndSave()`, `AdminNotification::createNotification()`.
- Produces: `#[AsMessageHandler] class TakeoverRepositoryCheckHandler` mit
  `__construct(LoggerInterface, BackupRepository, ManageRepository, RepositoryStats, AdminNotification)` und
  `__invoke(TakeoverRepositoryCheckMessage $message): void`.

Ablauf: Repository laden (fehlt es → loggen, Ende ohne Benachrichtigung). `checkRepositoryStructure()`
in `try/catch (\Throwable)`, danach `collectAndSave()` ebenso. Benachrichtigung im Format von
`Schedule/BackupExecution::notify()` (`source` `mucki-facility`, `adminOnly` true,
`requiredPrivileges` `['muwa_backup_repository:update']`):
Erfolg `status` `success`, Snippet `muwa-backup-repository.notification.takeoverCheckSucceeded`,
Parameter `name`, `status`; sonst `status` `error`, Snippet `...takeoverCheckFailed`, Parameter
`name`, `error` (erste Fehlermeldung). Der Handler wirft nie.

Snippets (en-GB / de-DE):
- `takeoverCheckSucceeded`: "Repository “{name}” taken over and checked: {status}" /
  "Repository „{name}“ übernommen und geprüft: {status}"
- `takeoverCheckFailed`: "Check of taken-over repository “{name}” failed: {error}" /
  "Prüfung des übernommenen Repositorys „{name}“ fehlgeschlagen: {error}"

- [ ] **Step 1: Failing Tests schreiben**
  - `testCheckRunsBeforeStats`: Aufrufreihenfolge per gemeinsamem `$calls`-Array → `['check', 'stats']`; Notification `success` mit `status`.
  - `testCheckFailureIsLoggedStatsStillRunAndErrorNotified`: Check wirft → `logger->error` einmal, `collectAndSave` einmal, Notification `error`, keine Exception.
  - `testStatsFailureIsNotifiedAsError`.
  - `testMissingRepositoryEndsQuietly`: weder Check noch Notification.
- [ ] **Step 2: Tests laufen lassen** → FAIL.
- [ ] **Step 3: Implementieren**, Handler in `services.xml` mit `messenger.message_handler`-Tag; Snippets eintragen.
- [ ] **Step 4: Tests laufen lassen** → PASS.

### Task 8: Pfad-Vorschlaege

**Files:**
- Create: `src/Services/BackupPathSuggestions.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Services/BackupPathSuggestionsTest.php`

**Interfaces:**
- Consumes: `ManageRepository::readSnapshots()`, `SettingsInterface::getBackupPath()`.
- Produces: `BackupPathSuggestions::collect(string $backupRepositoryId): array` mit der Struktur
  `{suggestions: list<{path: string, lastSeenAt: ?string (ATOM), hostname: string, snapshotCount: int, state: 'directory'|'file'|'missing', configured: bool}>, truncated: bool, error: ?string}`.
  Konstruktor: `LoggerInterface`, `BackupRepository`, `ManageRepository`, `SettingsInterface`, `PluginHelper`.
  Konstante `MAX_SUGGESTIONS = 200`.

Regeln (Spec "Pfad-Vorschlaege"): Pfade aus dem `paths`-Array jedes Snapshots (nie aus der DB-Spalte),
ohne Dubletten; pro Pfad juengster Snapshot (Zeitpunkt wie in Task 6) liefert `lastSeenAt` und
`hostname`; Sortierung nach `lastSeenAt` absteigend, `null` zuletzt; `state` ueber `is_dir`/`is_file`
auf dem logischen Pfad (kein `realpath`); `configured`, wenn der Pfad (ohne Schraegstrich am Ende)
in `backupPaths[*].backupPath` des Repositories steht; der Dump-Ordner
(`getBackupPath(false, dbDumpPath, repositoryPath, restorePath)`, ohne Schraegstrich am Ende) wird
entfernt; nach dem Deckeln `truncated = true`. Repository fehlt oder `readSnapshots` wirft →
`suggestions = []`, `error` = kurze Meldung ohne Passwort, geloggt.

- [ ] **Step 1: Failing Tests schreiben**
  - `testPathsAreDeduplicatedAndSortedByLastSeen` (drei Snapshots, ein Pfad doppelt; `snapshotCount` 2).
  - `testPathWithCommaStaysOnePath`: `['/srv/a,b']` → genau ein Eintrag `/srv/a,b`.
  - `testStateDirectoryFileMissing` (Temp-Ordner, Temp-Datei, nicht existierender Pfad).
  - `testConfiguredPathsAreMarked`.
  - `testDumpFolderIsFiltered`.
  - `testListIsCappedAndFlagged` (205 verschiedene Pfade → 200, `truncated` true).
  - `testResticFailureReturnsEmptyListWithError`.
  - `testRepositoryWithoutSnapshotsReturnsEmptyListWithoutError`.
- [ ] **Step 2: Tests laufen lassen** → FAIL.
- [ ] **Step 3: Implementieren**, Service eintragen.
- [ ] **Step 4: Tests laufen lassen** → PASS.

### Task 9: Controller mit Uebernahme- und Vorschlags-Route

**Files:**
- Create: `src/Controller/TakeoverBackupRepositoryController.php`
- Modify: `src/Resources/config/services.xml` (public, `setContainer` wie `InitBackupRepositoryController`)
- Test: `tests/Controller/TakeoverBackupRepositoryControllerTest.php`, `tests/Controller/RouteAclTest.php`

**Interfaces:**
- Consumes: `RepositoryTakeover::takeover()`, `RepositoryTakeoverException`, `BackupPathSuggestions::collect()`.
- Produces:
  - `POST /api/_action/muwa/backup/repository/takeover`, Name `api.action.muwa.backup.repository.takeover`,
    ACL `muwa_backup_repository:create`, Methode `takeover(RequestDataBag $data, Context $context): JsonResponse`.
    Eingabe `id`, `repositoryPath`, `repositoryPassword`. Erfolg `200 {success: true, id, snapshotsCount}`.
    `RepositoryTakeoverException` → `400 {errors: [{code, detail, meta}]}`. Andere Exceptions
    werden nicht abgefangen.
  - `POST /api/_action/muwa/backup/repository/path-suggestions`, Name
    `api.action.muwa.backup.repository.path_suggestions`, ACL `muwa_backup_repository:update`,
    Methode `pathSuggestions(RequestDataBag $data, Context $context): JsonResponse`. Eingabe `id`;
    ungueltige UUID → `400 {errors: [{code: 'MUWA_INVALID_ID', detail}]}`; sonst `200` mit dem
    Ergebnis von `collect()`.

- [ ] **Step 1: Failing Tests schreiben**
  - `testTakeoverReturnsIdAndCount`.
  - `testTakeoverErrorIsStructured`: Service wirft `NOT_WRITABLE` mit meta → Status 400, `errors[0].code`, `errors[0].meta.user`.
  - `testTakeoverResponseNeverContainsPassword`: Erfolg und Fehler, Response-Body ohne das Passwort.
  - `testPathSuggestionsRejectsInvalidId` und `testPathSuggestionsReturnsServiceResult`.
  - `RouteAclTest`: Eintraege `'repository takeover' => [TakeoverBackupRepositoryController::class, 'takeover', ['muwa_backup_repository:create']]`
    und `'repository path suggestions' => [..., 'pathSuggestions', ['muwa_backup_repository:update']]`.
- [ ] **Step 2: Tests laufen lassen** → FAIL.
- [ ] **Step 3: Implementieren.**
- [ ] **Step 4: Ganze Unit-Suite laufen lassen** → PASS; `cd custom/static-plugins/MuckiFacilityPlugin && composer run-script phpstan` → keine neuen Fehler.

### Task 10: Administration — Modal "Repository hinzufuegen"

**Files:**
- Create: `src/Resources/app/administration/src/module/muwa-backup-repository/component/muwa-backup-repository-add-modal/index.js`
- Create: `.../component/muwa-backup-repository-add-modal/muwa-backup-repository-add-modal.html.twig`
- Create: `.../component/muwa-backup-repository-add-modal/muwa-backup-repository-add-modal.scss`
- Modify: `.../module/muwa-backup-repository/index.js` (Registrierung)
- Modify: `.../page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig:14-22`, `.../page/muwa-backup-repository-list/index.js`
- Modify: `snippet/de-DE.json`, `snippet/en-GB.json` (neuer Block `takeover`)
- Test: `tests/Administration/RepositoryTakeoverAdministrationTest.php` (Stil `DatabaseConnectionAdministrationTest`)

**Interfaces:**
- Consumes: Route `takeover` (Task 9), Antwortformat dort.
- Produces: Komponente `muwa-backup-repository-add-modal`, Event `modal-close`. Liste: `data.showAddModal: false`.

Verhalten (Spec "Modal Repository hinzufuegen"):
- Registrierung `Shopware.Component.register('muwa-backup-repository-add-modal', () => import('./component/muwa-backup-repository-add-modal'));`
- Liste: Button "Repository anlegen" verliert `:routerLink`, bekommt `@click="showAddModal = true"`;
  `<muwa-backup-repository-add-modal v-if="showAddModal" @modal-close="showAddModal = false" />`.
  `:disabled` und `v-else` bleiben.
- `data`: `step: 'choice'|'takeover'`, `repositoryPath: ''`, `repositoryPassword: ''`, `isLoading: false`,
  `errorMessage: null`, `httpClient`, Feature-Flags wie in der Create-Seite.
- Schritt `choice`: zwei klickbare Optionen (`muwa-backup-repository-add-modal__option`), "Neues
  Repository" → `$emit('modal-close')` + `$router.push({ name: 'muwa.backup.repository.create' })`;
  "Uebernehmen" → `step = 'takeover'`. Footer "Abbrechen".
- Schritt `takeover`: `sw-alert` (info) mit `takeover.hint`, `muwa-backup-repository-path-field`
  (`v-model:value="repositoryPath"`, required), `sw-password-field` (`v-model:value`, required),
  bei `errorMessage` ein `sw-alert` (error). Footer "Zurueck" (`step = 'choice'`, Eingaben bleiben)
  und `sw-button-process` "Weiter" (`:isLoading`, `:disabled` bei leerem Pfad oder Passwort).
- `onSubmit()`: `POST /_action/muwa/backup/repository/takeover` mit `{ id: Shopware.Utils.createId(), repositoryPath, repositoryPassword }`
  und Headern wie `getApiHeader()` der Create-Seite. Erfolg → `createNotificationSuccess` mit
  `$t('muwa-backup-repository.takeover.successMessage', { count: response.data.snapshotsCount })`,
  `$emit('modal-close')`, `$router.push({ name: 'muwa.backup.repository.detail', params: { id: response.data.id, tab: 'backupRepositoryConfig' } })`.
  Fehler → `errorMessage = getErrorMessage(exception)`: `errors[0].code` → Snippet
  `takeover.errors.<CODE>` mit `errors[0].meta` als Platzhalter, wenn `$te()` den Key kennt; sonst
  `errors[0].detail`; ohne Response `takeover.errors.unknown`.
- Waehrend `isLoading` laesst sich das Modal nicht schliessen (`@modal-close` ignorieren,
  Footer-Buttons deaktiviert). Schliessen setzt nichts zurueck, weil die Komponente per `v-if`
  verworfen wird — dadurch ist auch das Passwort weg.

Snippets `takeover.*` (en-GB / de-DE, Texte fuer de-DE):
`modalTitle` "Repository hinzufügen", `optionNewTitle` "Neues Repository",
`optionNewDescription` "Legt ein leeres restic-Repository an.", `optionTakeoverTitle`
"Bestehendes Repository übernehmen", `optionTakeoverDescription` "Nimmt ein vorhandenes lokales
restic-Repository samt Snapshots in die Verwaltung auf.", `hint` "Das Repository bleibt, wo es
ist, und wird nur gelesen. Die Snapshots werden übernommen, die übrige Konfiguration ergänzt du
danach. Schalte den bisherigen Batch- oder Cronjob ab, damit nicht zwei Stellen in dasselbe
Repository sichern und aufräumen. Künftige Aufbewahrungsregeln (Forget) betreffen auch die
übernommenen Snapshots.", `repositoryPathLabel` "Pfad des restic-Repositorys", `passwordLabel`
"Repository-Passwort", `cancel` "Abbrechen", `back` "Zurück", `next` "Weiter", `successTitle`
"Repository übernommen", `successMessage` "{count} Snapshots übernommen. Bitte ergänze die
Konfiguration.", `errors.MUWA_TAKEOVER_INVALID_INPUT` "Pfad oder Passwort ist ungültig.",
`errors.MUWA_TAKEOVER_PATH_NOT_FOUND` "Der Pfad existiert nicht oder ist nicht lesbar.",
`errors.MUWA_TAKEOVER_NOT_A_REPOSITORY` "Unter diesem Pfad liegt kein restic-Repository.",
`errors.MUWA_TAKEOVER_NOT_WRITABLE` "Der Benutzer „{user}“ braucht Schreibrecht auf „{path}“.",
`errors.MUWA_TAKEOVER_DUPLICATE` "Dieses Repository wird bereits als „{name}“ verwaltet.",
`errors.MUWA_TAKEOVER_PASSWORD_NOT_STORABLE` "Das Passwort kann nicht verschlüsselt gespeichert
werden (MUWA_FACILITY_SECRET prüfen).", `errors.MUWA_TAKEOVER_ACCESS_FAILED` "Zugriff auf das
Repository fehlgeschlagen. Passwort prüfen.", `errors.MUWA_TAKEOVER_IMPORT_FAILED` "Die Snapshots
konnten nicht übernommen werden. Es wurde nichts gespeichert.", `errors.unknown` "Die Übernahme
ist fehlgeschlagen."

- [ ] **Step 1: Failing Admin-Test schreiben** (`RepositoryTakeoverAdministrationTest`):
  - `testModalIsRegistered` (Registrierungszeile in `index.js`).
  - `testCreateButtonOpensModalInsteadOfLink`: Listen-Template enthaelt `@click="showAddModal = true"` und
    `<muwa-backup-repository-add-modal`, aber nicht mehr `:routerLink="{name: 'muwa.backup.repository.create'}"`.
  - `testModalPostsTakeoverAndRoutesToConfigTab`: `index.js` des Modals enthaelt
    `/_action/muwa/backup/repository/takeover`, `tab: 'backupRepositoryConfig'`, `name: 'muwa.backup.repository.create'`.
  - `testModalUsesTWithPlaceholdersNotTc`: kein `$tc(` mit drei Argumenten im Modal.
  - `testAllTakeoverSnippetsExistInBothLanguages`: jeder Key aus en-GB `takeover` existiert in de-DE und umgekehrt; alle acht `MUWA_TAKEOVER_*`-Codes vorhanden.
- [ ] **Step 2: Test laufen lassen** → FAIL.
- [ ] **Step 3: Modal, Liste und Snippets implementieren.**
- [ ] **Step 4: Test laufen lassen** → PASS.
- [ ] **Step 5: Administration bauen** — aus `~/shopdev/sw6/sw67` `ddev exec bin/build-administration.sh` → ohne Fehler.

### Task 11: Administration — Pfad-Vorschlaege im Tab Konfiguration

**Files:**
- Modify: `.../page/muwa-backup-repository-detail/index.js`
- Modify: `.../page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig` (Karte "Backup-Pfade", ab Zeile 380)
- Modify: `.../page/muwa-backup-repository-detail/muwa-backup-repository-detail.scss`
- Modify: `snippet/de-DE.json`, `snippet/en-GB.json` (Block `takeover.suggestions`)
- Test: `tests/Administration/RepositoryTakeoverAdministrationTest.php` (erweitern)

**Interfaces:**
- Consumes: Route `path-suggestions` (Task 9).
- Produces: in der Detail-Seite `data.pathSuggestions: []`, `data.isLoadingPathSuggestions: false`,
  `data.pathSuggestionsError: null`, computed `openPathSuggestions` (nicht `configured` und nicht
  bereits in `backupRepository.backupPaths` als `backupPath`), Methoden `loadPathSuggestions()` und
  `onAdoptPathSuggestion(suggestion)`.

Verhalten (Spec "Detail-Seite, Tab Konfiguration"):
- `loadPathSuggestions()` nur bei `acl.can('muwa_backup_repository.editor')`, sobald das Repository
  geladen ist und `tab === 'backupRepositoryConfig'` (auch beim Tab-Wechsel dorthin, einmal pro
  Seitenaufruf). Nicht blockierend: eigene Ladeanzeige im Bereich, Fehler setzt `pathSuggestionsError`
  und zeigt einen dezenten Hinweis.
- Bereich `muwa-backup-repository-detail-path-suggestions` ueber der Pfad-Tabelle, nur bei
  `openPathSuggestions.length > 0` oder laufendem Laden: je Eintrag Pfad, Vermerk
  (`stateDirectory` / `stateFile` / `stateMissing`, letzterer als Warnung), `lastSeenAt` per
  `dateFilter`, Host, Button "Uebernehmen".
- `onAdoptPathSuggestion(s)`: wie `onAddBackupPath()` (andere Positionen +1, `unshift` mit `position: 0`,
  `compress: false`, `isDefault: false`, `id: createId()`), aber `backupPath: s.path`. Kein API-Aufruf;
  gespeichert wird mit dem normalen Speichern. Der Eintrag verschwindet ueber `openPathSuggestions`.

Snippets `takeover.suggestions.*` (de-DE): `title` "Pfade aus vorhandenen Snapshots",
`adopt` "Übernehmen", `stateDirectory` "vorhanden", `stateFile` "Datei", `stateMissing` "nicht
gefunden", `lastSeen` "zuletzt gesichert", `host` "Host", `error` "Die Vorschläge konnten nicht
geladen werden.", `truncated` "Es werden nur die ersten 200 Pfade angezeigt."

- [ ] **Step 1: Admin-Test erweitern**
  - `testDetailLoadsSuggestionsOnlyForEditor`: `index.js` enthaelt `/_action/muwa/backup/repository/path-suggestions`
    und vor dem Aufruf `acl.can('muwa_backup_repository.editor')`.
  - `testAdoptAddsPathWithoutSaving`: `onAdoptPathSuggestion` enthaelt `backupPath: suggestion.path`, `compress: false`
    und keinen `httpClient`-/`repository.save`-Aufruf im Methodenrumpf.
  - `testSuggestionSnippetsExistInBothLanguages`.
- [ ] **Step 2: Test laufen lassen** → FAIL.
- [ ] **Step 3: Implementieren.**
- [ ] **Step 4: Test laufen lassen** → PASS; Administration erneut bauen → ohne Fehler.

### Task 12: Dokumentation und Gesamtpruefung

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `CLAUDE.md` des Plugins

- [ ] **Step 1: Doku schreiben.**
  - `README.md`: Abschnitt "Bestehendes Repository übernehmen" (Ablauf im Modal, nur lokale Pfade,
    Schreibrechte des Webserver-Benutzers, alten Cronjob abschalten, Forget-Regeln betreffen alte
    Snapshots, Hostname aus dem jüngsten Snapshot, Status-Tab erst nach Worker-Lauf bzw.
    `muckiware:backup:check` + `muckiware:repository:stats`).
  - `CHANGELOG.md`: Abschnitt `0.10.0` (Added: Übernahme, Pfad-Vorschläge, Modal; Changed:
    `muckiware/restic ^1.6`, Spalte `paths` LONGTEXT, toleranter Snapshot-Import, UTC-Zeitstempel).
  - `CLAUDE.md`: API-Tabelle um beide Routen, MessageQueue um `TakeoverRepositoryCheckMessage`/-Handler,
    Administration um das Modal, Fakten-Tabelle um Version `v0.10.0` und `muckiware/restic ^1.6`.
- [ ] **Step 2: Gesamte Unit-Suite** → alle gruen. **PHPStan** → keine neuen Fehler.
- [ ] **Step 3: Manuell im Browser (6.7, DDEV)**, Repository vorher per CLI anlegen
  (`restic init` + zwei `restic backup` unter einem eigenen `--host`), Worker laufen lassen
  (`ddev exec bin/console messenger:consume async --time-limit=60`):
  Modal oeffnet sich, "Neues Repository" fuehrt zur unveraenderten Create-Seite; Uebernahme gelingt,
  Sprung in Tab Konfiguration, Name = Ordnername, Hostname = Host der CLI-Snapshots; Snapshots-Tab
  zeigt beide; falsches Passwort, Pfad ohne Repository, nicht schreibbares Repository
  (`chmod -R a-w`), doppelte Uebernahme → jeweils Meldung im Modal, kein Datensatz; Vorschlagsliste
  zeigt die gesicherten Pfade, "Uebernehmen" + Speichern traegt sie ein; nach dem Worker-Lauf
  Status-Tab gefuellt und Admin-Benachrichtigung da.
- [ ] **Step 4: Stichprobe 6.6** — falls ein 6.6-Shop greifbar ist, Modal und Vorschlagsliste dort
  oeffnen; sonst im Abschluss als nicht geprueft melden.
- [ ] **Step 5: Uebergabe** — `git status` in Plugin und Library zeigen, Diff-Zusammenfassung an den
  Maintainer. Nicht committen.
