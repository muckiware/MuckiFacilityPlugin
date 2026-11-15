# Download wiederhergestellter Datenbank-Dumps — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Im Tab Snapshots ein Panel, das den Restore Path als lazy Ordnerbaum zeigt und Datenbank-Dumps daraus herunterladen laesst — nur mit Restore-Recht und nur innerhalb eines zulaessigen Restore Path.

**Architecture:** `Settings::getRestorePathRejection()` prueft den Restore Path ueber dieselbe Regelquelle wie den Dump-Pfad. `Services\RestoreDirectoryBrowser` listet einen Ordner relativ zum Restore Path und loest Download-Dateien sicher auf. `Controller\RestoreDirectoryController` bietet Listing und Download-Vorbereitung (ACL `muwa_backup_repository:restore`) sowie einen tokengeschuetzten Download-GET ohne Bearer-Header — dasselbe Muster wie Shopwares Import/Export-Download (`prepare-download` + `auth_required: false`), damit der Browser grosse Dumps direkt auf die Platte streamt statt sie als Blob im Speicher zu halten. Die Administration bekommt eine Komponente `muwa-restore-directory-tree`, eingebunden als `sw-card` unter der Snapshot-Liste.

**Tech Stack:** PHP 8.2–8.4, Shopware 6.6 + 6.7, Symfony `BinaryFileResponse`, Cache-Pool `cache.object`, Vue (Options-API, `Component.register`), PHPUnit ueber `phpunit.unit.xml`.

**Spec:** `docs/superpowers/specs/2026-11-12-restore-dump-download-design.md`

## Global Constraints

- Namespace `MuckiFacilityPlugin\`; Stil wie Bestand (`protected`, kein `final`/`readonly`, `declare(strict_types=1)`, Datei-Header-Kommentar wie in den Nachbardateien).
- Jeder Service in `src/Resources/config/services.xml` eintragen — kein Autowiring.
- Jeder neue Unit-Test als `<file>` in `phpunit.unit.xml` eintragen.
- ACL fuer Listing und Download-Vorbereitung: exakt `['muwa_backup_repository:restore']`.
- Als Dump gelten Dateinamen mit Endung `.backup.sql.tar.gz`, `.backup.sql.gz` oder `.backup.sql` — nichts anderes ist herunterladbar.
- Relativer Restore Path wird relativ zum Projektverzeichnis aufgeloest (`Settings::resolveDumpPathValue()`).
- Keine restic-Aufrufe, keine Aenderung an Restore, Snapshot-Liste oder Configuration-Formular.
- Kein Loeschen/Umbenennen ueber das Panel.
- Administration muss auf 6.6 **und** 6.7 laufen: kein `sw-select-field`, keine `$listeners`/`$scopedSlots`, Slots nur per `<template #name>`.
- Snippets in `de-DE.json` und `en-GB.json` unter dem Schluessel `muwa-backup-repository.restoreDirectory`.

## Review Focus

1. **Symlink im Restore Path, der nach draussen zeigt** (z. B. `restore/x -> /etc`) — Listing darf ihn nicht betreten, Download muss ihn abweisen. Test in Task 2 (`testSymlinkOutsideRestorePathIsRejected`).
2. **Download-Token wird zweimal oder nach Ablauf benutzt** — zweiter Aufruf liefert 404, nicht die Datei. Test in Task 3 (`testTokenIsSingleUse`).
3. **Restore Path existiert noch nicht** (noch nie wiederhergestellt) — Panel zeigt Leerzustand, kein 400/500. Test in Task 2 (`testMissingRestoreDirectoryReturnsEmptyState`).
4. **Datei wird zwischen Token-Ausgabe und Download ausgetauscht** (z. B. durch Symlink ersetzt) — Download prueft Pfad beim Einloesen erneut. Test in Task 3 (`testDownloadRevalidatesPathOnRedeem`).
5. **Restore Path ueberschneidet sich mit dem Default-Dump-Pfad** `<projectDir>/var/db/backup`, obwohl am Repository kein eigener Dump-Pfad gesetzt ist — abgelehnt. Test in Task 1 (`testRestorePathOverlappingDefaultDumpPathIsRejected`).

---

### Task 1: Regel fuer zulaessigen Restore Path

**Files:**
- Modify: `src/Core/DumpPathRejection.php` (neuer Case)
- Modify: `src/Services/Settings.php:189-236` (gemeinsame Regelmethode extrahieren, neue public Methode)
- Modify: `src/Services/SettingsInterface.php`
- Modify: `src/Resources/app/administration/src/module/muwa-backup-repository/snippet/de-DE.json`, `en-GB.json` (Text fuer neuen Case unter `pathField.dumpPath`, damit die bestehende Warnung nie einen fehlenden Key zeigt)
- Test: `tests/Services/SettingsTest.php`

**Interfaces:**
- Produces: `DumpPathRejection::OVERLAPS_DUMP_PATH = 'overlapsDumpPath'`
- Produces: `SettingsInterface::getRestorePathRejection(string $restorePath, ?string $repositoryPath, ?string $ownDumpPath): ?DumpPathRejection`
- Produces: Konstante bleibt `Defaults::DATABASE_BACKUP_PATH` (`/var/db/backup`) — wird als Default-Dump-Pfad mitgeprueft.

- [ ] **Step 1: Failing Tests in `SettingsTest` schreiben** (Projektverzeichnis dort `/var/www/html`)

```php
/** @dataProvider rejectedRestorePathProvider */
public function testRestorePathIsRejected(string $restorePath, ?string $repositoryPath, ?string $ownDumpPath, DumpPathRejection $expected): void
{
    static::assertSame($expected, $this->createSettings()->getRestorePathRejection($restorePath, $repositoryPath, $ownDumpPath));
}

public static function rejectedRestorePathProvider(): array
{
    return [
        'root'                  => ['/', null, null, DumpPathRejection::ROOT_DIRECTORY],
        'dot segment'           => ['/mnt/../etc', null, null, DumpPathRejection::RELATIVE_SEGMENT],
        'project dir'           => ['/var/www/html', null, null, DumpPathRejection::PROJECT_DIR_OR_PARENT],
        'parent of project'     => ['/var/www', null, null, DumpPathRejection::PROJECT_DIR_OR_PARENT],
        'blocked system dir'    => ['/etc/restore', null, null, DumpPathRejection::BLOCKED_PATH],
        'blocked project sub'   => ['vendor/restore', null, null, DumpPathRejection::BLOCKED_PATH],
        'repository overlap'    => ['/mnt/repo/restore', '/mnt/repo', null, DumpPathRejection::OVERLAPS_REPOSITORY_OR_RESTORE_PATH],
        'own dump path overlap' => ['/mnt/dumps', null, '/mnt/dumps/db', DumpPathRejection::OVERLAPS_DUMP_PATH],
    ];
}

public function testRestorePathOverlappingDefaultDumpPathIsRejected(): void
{
    static::assertSame(
        DumpPathRejection::OVERLAPS_DUMP_PATH,
        $this->createSettings()->getRestorePathRejection('var/db', null, null)
    );
}

public function testAllowedRestorePathsReturnNull(): void
{
    $settings = $this->createSettings();
    static::assertNull($settings->getRestorePathRejection('/mnt/restore', '/mnt/repo', '/mnt/dumps'));
    static::assertNull($settings->getRestorePathRejection('var/restore', null, null));
}
```

- [ ] **Step 2: Tests laufen lassen, erwartet FAIL** (`getRestorePathRejection` undefiniert)

Run (aus `/Users/torstenfreyda/shopdev/muckidrive`): `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml --filter RestorePath"`

- [ ] **Step 3: Implementieren**

Die bestehende Logik von `getOwnDumpPathRejection()` (ab `rtrim`) in eine protected Methode `getPathRejection(string $path, array $protectedPaths): ?DumpPathRejection` auslagern, wobei `$protectedPaths` ein `array<string, DumpPathRejection>` ist (Pfad → Grund bei Ueberschneidung). `getOwnDumpPathRejection()` behaelt sein Verhalten exakt (leer → `null`; Repository- und Restore-Path → `OVERLAPS_REPOSITORY_OR_RESTORE_PATH`). `getRestorePathRejection()`: leerer/nur-Whitespace-Wert → `null` (der Aufrufer meldet "nicht konfiguriert" vorher selbst); `'/'` → `ROOT_DIRECTORY`; geschuetzt sind `repositoryPath` (→ `OVERLAPS_REPOSITORY_OR_RESTORE_PATH`), aufgeloester `ownDumpPath` falls gesetzt und `projectDir . Defaults::DATABASE_BACKUP_PATH` (beide → `OVERLAPS_DUMP_PATH`). Methode im Interface ergaenzen. Neuer Enum-Case plus Snippet `pathField.dumpPath.overlapsDumpPath` (DE: „Pfad überschneidet sich mit dem Datenbank-Dump-Pfad“, EN: „Path overlaps the database dump path“).

- [ ] **Step 4: Ganze Unit-Suite laufen lassen, erwartet PASS** (bestehende Dump-Pfad-Tests muessen unveraendert gruen bleiben)

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`

- [ ] **Step 5: Commit**

```bash
git add src/Core/DumpPathRejection.php src/Services/Settings.php src/Services/SettingsInterface.php tests/Services/SettingsTest.php src/Resources/app/administration/src/module/muwa-backup-repository/snippet
git commit -m "feat: add restore path rejection rules"
```

---

### Task 2: `RestoreDirectoryBrowser` — Listing und sichere Dateiaufloesung

**Files:**
- Create: `src/Services/RestoreDirectoryBrowser.php`
- Modify: `src/Resources/config/services.xml` (Service mit `Services\Content\BackupRepository`, `Services\SettingsInterface`)
- Modify: `phpunit.unit.xml`
- Test: `tests/Services/RestoreDirectoryBrowserTest.php`

**Interfaces:**
- Consumes: `SettingsInterface::getRestorePathRejection()`, `SettingsInterface::resolveDumpPathValue()` (Task 1), `Content\BackupRepository::getBackupRepositoryById(string): ?BackupRepositoryEntity`, `BackupRepositoryEntity::getRestorePath()`, `getRepositoryPath()`, `getDbDumpPath()`
- Produces:
  - `RestoreDirectoryBrowser::DUMP_FILE_SUFFIXES = ['.backup.sql.tar.gz', '.backup.sql.gz', '.backup.sql']`, `MAX_ENTRIES = 1000`
  - `listDirectory(string $backupRepositoryId, ?string $relativePath): array` mit Shape
    `{state: 'ok'|'notConfigured'|'notAllowed'|'empty', rejection: ?string, path: string, parent: ?string, directories: list<{name: string, path: string}>, files: list<{name: string, path: string, size: int, modifiedAt: string, downloadable: bool}>, truncated: bool}` — alle `path`/`parent` **relativ** zum Restore Path (`''` = Wurzel), `modifiedAt` als ATOM.
  - `resolveDownloadFile(string $backupRepositoryId, string $relativePath): string` — absoluter, real aufgeloester Pfad einer herunterladbaren Datei.
  - Wirft `Exception\InvalidBrowsePathException` bei ungueltigem relativen Pfad, unbekanntem Repository, Nicht-Dump, nicht lesbarer Datei oder `state != ok` beim Download.

- [ ] **Step 1: Failing Tests schreiben** (Temp-Verzeichnis wie in `DirectoryBrowserTest`; Repository-Service und Settings gemockt, `resolveDumpPathValue` gibt Argument zurueck, `getRestorePathRejection` per Default `null`)

```php
testListsRootWithDumpsDownloadableAndOtherFilesNot()
    // restore/var/db/backup/2026-11-12_shop.backup.sql.tar.gz + restore/notes.txt
    // listDirectory($id, '') → state 'ok', directories ['var'], files ['notes.txt'] mit downloadable false
    // listDirectory($id, 'var/db/backup') → files[0] name ..., downloadable true, path 'var/db/backup/2026-11-12_shop.backup.sql.tar.gz', parent 'var/db'
testAllThreeDumpSuffixesAreDownloadable()            // .backup.sql.tar.gz, .backup.sql.gz, .backup.sql → true; 'dump.sql' und 'x.backup.sql.bak' → false
testEmptyRestorePathReturnsNotConfigured()           // getRestorePath() '' → state 'notConfigured', leere Listen
testRejectedRestorePathReturnsNotAllowed()           // getRestorePathRejection → BLOCKED_PATH → state 'notAllowed', rejection 'blockedPath'
testMissingRestoreDirectoryReturnsEmptyState()       // Restore Path existiert nicht → state 'empty', kein Throw
/** @dataProvider invalidRelativePathProvider */
testInvalidRelativePathThrows(string $path)          // '../x', 'a/../../x', '/etc', "a\0b" → InvalidBrowsePathException
testSymlinkOutsideRestorePathIsRejected()            // restore/out -> $outside/; listDirectory('') listet 'out' nicht; listDirectory('out') und resolveDownloadFile('out/x.backup.sql') werfen
testResolveDownloadFileReturnsRealPathOfDump()
testResolveDownloadFileRejectsNonDumpAndDirectories()
testUnknownRepositoryThrows()
testListingIsTruncatedAtMaxEntries()                 // MAX_ENTRIES + 1 Dateien → truncated true, count == MAX_ENTRIES
```

- [ ] **Step 2: Tests laufen lassen, erwartet FAIL** (Klasse fehlt)

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml --filter RestoreDirectoryBrowserTest"`

- [ ] **Step 3: Implementieren**

Ablauf je Aufruf: Repository laden → `restorePath` trimmen; leer → `notConfigured` → `getRestorePathRejection(restorePath, repositoryPath, dbDumpPath)`; Treffer → `notAllowed` → `resolveDumpPathValue(restorePath)`; kein Verzeichnis → `empty` → Wurzel per `realpath()`. Relativen Pfad vor jedem Dateisystemzugriff pruefen (Null-Byte, fuehrender `/`, Segment `.`/`..` → Exception), dann `realpath(root/rel)` muss `=== root` sein oder mit `root.'/'` beginnen. Im Listing Symlinks (`is_link`) und nicht-UTF-8-Namen ueberspringen, `strnatcasecmp` sortieren, Ordner und Dateien je auf `MAX_ENTRIES` kappen.

- [ ] **Step 4: Tests laufen lassen, erwartet PASS** (gleicher Befehl, danach ganze Suite)

- [ ] **Step 5: Commit**

```bash
git add src/Services/RestoreDirectoryBrowser.php src/Resources/config/services.xml phpunit.unit.xml tests/Services/RestoreDirectoryBrowserTest.php
git commit -m "feat: add restore directory browser service"
```

---

### Task 3: Download-Token und API-Routen

**Files:**
- Create: `src/Services/RestoreDownloadToken.php`
- Create: `src/Controller/RestoreDirectoryController.php`
- Modify: `src/Resources/config/services.xml` (Token-Service mit `cache.object`; Controller `public="true"` + `setContainer` wie `FilesystemController`)
- Modify: `tests/Controller/RouteAclTest.php`
- Modify: `phpunit.unit.xml`
- Test: `tests/Services/RestoreDownloadTokenTest.php`, `tests/Controller/RestoreDirectoryControllerTest.php`

**Interfaces:**
- Consumes: `RestoreDirectoryBrowser::listDirectory()`, `resolveDownloadFile()` (Task 2)
- Produces:
  - `RestoreDownloadToken::__construct(CacheItemPoolInterface $cache)`, `TTL_SECONDS = 60`
  - `issue(string $backupRepositoryId, string $relativePath): string` (zufaellig, `bin2hex(random_bytes(32))`, Cache-Key `muwa_restore_download_<token>`)
  - `redeem(string $token): ?array{backupRepositoryId: string, path: string}` — loescht den Eintrag beim Einloesen; ungueltig/abgelaufen → `null`
  - Routen in `RestoreDirectoryController`:
    - `POST /api/_action/muwa/restore/directory`, name `api.action.muwa.restore.directory`, ACL restore, Body `backupRepositoryId`, `path` → JSON aus `listDirectory`
    - `POST /api/_action/muwa/restore/download/prepare`, name `api.action.muwa.restore.download_prepare`, ACL restore, Body `backupRepositoryId`, `path` → validiert per `resolveDownloadFile`, dann `{accessToken: string}`
    - `GET /api/_action/muwa/restore/download`, name `api.action.muwa.restore.download`, `defaults: ['auth_required' => false]`, Query `accessToken` → `BinaryFileResponse` mit `Content-Disposition: attachment; filename=<basename>`, `Content-Type: application/octet-stream`
  - Fehler: ungueltiger Pfad/Datei → 400 `MUWA_INVALID_PATH` (wie `FilesystemController::invalidPathResponse`); unbekannter/abgelaufener Token → 404 `MUWA_INVALID_DOWNLOAD_TOKEN`

- [ ] **Step 1: Failing Tests schreiben**

`RestoreDownloadTokenTest` (mit `Symfony\Component\Cache\Adapter\ArrayAdapter`):
```php
testIssuedTokenRedeemsToRepositoryAndPath()   // redeem(issue('id','a/b.backup.sql')) === ['backupRepositoryId'=>'id','path'=>'a/b.backup.sql']
testTokenIsSingleUse()                         // zweites redeem → null
testUnknownTokenReturnsNull()
testTokenIsRandomAnd64HexChars()               // zwei issue() verschieden, preg_match('/^[0-9a-f]{64}$/')
```

`RestoreDirectoryControllerTest` (Browser + Token gemockt):
```php
testDirectoryReturnsBrowserResult()            // ->with('repo-id', 'var') ; 200 ; JSON identisch
testDirectoryReturns400OnInvalidPath()         // MUWA_INVALID_PATH
testPrepareValidatesFileBeforeIssuingToken()   // resolveDownloadFile wirft → 400, issue() never
testPrepareReturnsAccessToken()                // {accessToken: 'tok'}
testDownloadWithUnknownTokenReturns404()       // MUWA_INVALID_DOWNLOAD_TOKEN
testDownloadRevalidatesPathOnRedeem()          // redeem ok, resolveDownloadFile wirft → 400, keine Datei
testDownloadStreamsFileAsAttachment()          // echte Temp-Datei; BinaryFileResponse; Disposition enthaelt attachment + Dateiname
```

`RouteAclTest`: Provider um `'restore directory'` und `'restore download prepare'` (beide `['muwa_backup_repository:restore']`) erweitern. Neuer Provider `publicTokenRouteProvider()` mit genau `RestoreDirectoryController::download`; `testEveryRouteInControllerNamespaceIsCovered()` zaehlt diese als abgedeckt; neuer Test `testPublicRoutesRequireNoAuthButAreExplicitlyListed()` prueft dort `auth_required === false` und kein `_acl`.

- [ ] **Step 2: Tests laufen lassen, erwartet FAIL**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml --filter 'RestoreDownloadToken|RestoreDirectoryController|RouteAcl'"`

- [ ] **Step 3: Implementieren** — Token-Service und Controller wie in Interfaces; Request-Werte ueber eine `getString()`-Hilfe wie in `FilesystemController` (Nicht-Strings → `''` → 400).

- [ ] **Step 4: Tests laufen lassen, erwartet PASS**, danach ganze Suite und PHPStan

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`
Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && composer run-script phpstan"` → `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Services/RestoreDownloadToken.php src/Controller/RestoreDirectoryController.php src/Resources/config/services.xml phpunit.unit.xml tests/Services/RestoreDownloadTokenTest.php tests/Controller/RestoreDirectoryControllerTest.php tests/Controller/RouteAclTest.php
git commit -m "feat: add restore directory listing and dump download routes"
```

---

### Task 4: Administration — Panel mit Ordnerbaum

**Files:**
- Create: `src/Resources/app/administration/src/module/muwa-backup-repository/component/muwa-restore-directory-tree/index.js`, `muwa-restore-directory-tree.html.twig`, `muwa-restore-directory-tree.scss`
- Modify: `src/Resources/app/administration/src/module/muwa-backup-repository/index.js` (async `Shopware.Component.register` wie `muwa-backup-repository-path-field`)
- Modify: `.../page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig` (neue `sw-card` direkt nach `</sw-entity-listing>` im Snapshots-Tab)
- Modify: `.../page/muwa-backup-repository-detail/index.js` (`onRefresh()` ruft zusaetzlich `this.$refs.restoreDirectoryTree?.refresh()`)
- Modify: `snippet/de-DE.json`, `snippet/en-GB.json`
- Test: `tests/Administration/RestoreDirectoryTreeAdministrationTest.php`, `phpunit.unit.xml`

**Interfaces:**
- Consumes: die drei Routen aus Task 3 (Admin-Pfade ohne `/api`: `/_action/muwa/restore/directory`, `/_action/muwa/restore/download/prepare`; Download-URL `${Shopware.Context.api.apiPath}/_action/muwa/restore/download?accessToken=<token>`)
- Produces: Komponente `muwa-restore-directory-tree`, Prop `backupRepositoryId: String (required)`, public Methode `refresh()`.

Einbindung im Detail-Template:
```twig
<sw-card
    positionIdentifier="muwaBackupRepositoryRestoreDirectory"
    v-if="backupRepository && tab === 'backupRepositorySnapshots' && acl.can('muwa_backup_repository.restore')"
    :title="$tc('muwa-backup-repository.restoreDirectory.title')"
>
    <muwa-restore-directory-tree ref="restoreDirectoryTree" :backup-repository-id="backupRepository.id" />
</sw-card>
```

Snippet-Keys unter `muwa-backup-repository.restoreDirectory` (DE / EN):
`title` Wiederhergestellte Dateien / Restored files · `emptyState` Noch nichts wiederhergestellt. Stelle zuerst einen Snapshot wieder her. / Nothing restored yet. Restore a snapshot first. · `notConfigured` Für dieses Repository ist kein Restore Path konfiguriert. / No restore path is configured for this repository. · `notAllowed` Restore Path nicht zulässig – Anzeige gesperrt. / Restore path not allowed – view blocked. · `truncated` Liste gekürzt (max. 1000 Einträge). / List truncated (max. 1000 entries). · `downloadStarted` Download gestartet / Download started · `errorTitle` Fehler / Error · `loadError` Ordner konnte nicht geladen werden. / Folder could not be loaded. · `downloadError` Download fehlgeschlagen. / Download failed.

- [ ] **Step 1: Failing statischen Admin-Test schreiben** (Stil wie `BatchFileUploadAdministrationTest`, liest Dateien als Text)

```php
testComponentIsRegisteredAsync()          // index.js enthaelt "Shopware.Component.register('muwa-restore-directory-tree', () => import('./component/muwa-restore-directory-tree'))"
testPanelIsGatedByRestoreAcl()            // Detail-Template enthaelt "acl.can('muwa_backup_repository.restore')" in der sw-card mit positionIdentifier="muwaBackupRepositoryRestoreDirectory", nach '</sw-entity-listing>'
testRefreshAlsoReloadsTree()              // Detail-index.js enthaelt 'this.$refs.restoreDirectoryTree?.refresh()'
testComponentUsesRoutesAndTokenDownload() // Komponente enthaelt '/_action/muwa/restore/directory', '/_action/muwa/restore/download/prepare', 'accessToken'
testSnippetsExistInBothLanguages()        // alle oben genannten Keys in de-DE und en-GB, exakte Werte fuer title und notAllowed
```

- [ ] **Step 2: Test laufen lassen, erwartet FAIL**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml --filter RestoreDirectoryTreeAdministrationTest"`

- [ ] **Step 3: Komponente implementieren**

Zustand: Map relativer Pfad → `{ expanded, loading, loaded, directories, files, truncated }`; Wurzel `''` wird in `created()` und von `refresh()` geladen (refresh verwirft die Map). Ordner beim ersten Aufklappen per POST laden. `httpClient` und Header wie in der Detail-Seite (`Shopware.Application.getContainer('init').httpClient`, Bearer aus `Shopware.Service('loginService').getToken()`). Je `state` die passende Meldung statt Baum. Dumps als Link/Button mit Groesse (lesbar formatiert) und Datum; andere Dateien als Text. Download: POST prepare → `window.location.href = <Download-URL>`; Fehler per Notification-Mixin. Nur `sw-icon`, `sw-loader` und schlichtes HTML verwenden.

- [ ] **Step 4: Test laufen lassen, erwartet PASS**; dann Admin bauen (`bin/build-administration.sh` im Shop-Root) und im Browser auf 6.7 pruefen: Snapshot wiederherstellen → Refresh → Ordner aufklappen → Dump laden; als Nutzer ohne Restore-Recht ist das Panel nicht sichtbar. Wenn verfuegbar, dieselbe Pruefung auf einem 6.6-Shop.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/muwa-backup-repository tests/Administration/RestoreDirectoryTreeAdministrationTest.php phpunit.unit.xml
git commit -m "feat: add restored files panel with dump download to snapshots tab"
```

---

### Task 5: Dokumentation

**Files:**
- Modify: `CHANGELOG.md` (Abschnitt `### [0.9.0]` oben anlegen bzw. ergaenzen, `### Added`)
- Modify: `README.md` (Abschnitt zum Snapshots-Tab: Panel, Restore-Recht, nur Dumps, Restore Path muss zulaessig sein)
- Modify: `CLAUDE.md` (API-Routen-Tabelle um die drei Routen; Hinweis auf tokengeschuetzte Public-Route und die Ausnahme in `RouteAclTest`)

- [ ] **Step 1: Texte schreiben** (englisch in CHANGELOG/README wie Bestand, deutsch in CLAUDE.md wie Bestand)
- [ ] **Step 2: Ganze Unit-Suite ein letztes Mal laufen lassen, erwartet PASS**
- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md CLAUDE.md
git commit -m "docs: document restored dump download"
```
