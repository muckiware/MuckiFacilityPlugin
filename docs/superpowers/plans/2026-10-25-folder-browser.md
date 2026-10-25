# Ordner-Browser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Pfadfelder eines Backup-Repositories bekommen eine Aufklapp-Liste, mit der man Ordner auf dem Server per Klick auswaehlt; der Database dump path zeigt zusaetzlich live, ob das Backup den Wert ablehnen oder nicht beschreiben koennte.

**Architecture:** Ein neuer `Services\DirectoryBrowser` listet Unterordner (rein lesend, logische Pfade, kein `realpath()`) und prueft Dump-Pfade ueber die aus `Settings::resolveOwnDumpPath()` herausgeloeste Regelpruefung. Ein neuer `Controller\FilesystemController` macht beides ueber zwei API-Routes mit ACL `muwa_backup_repository:update` erreichbar. Eine neue Admin-Komponente `muwa-backup-repository-path-field` kapselt `sw-text-field` + Aufklapp-Liste + Dump-Pfad-Hinweis und ersetzt die bisherigen Textfelder auf Anlegen- und Detail-Seite.

**Tech Stack:** Shopware 6.6/6.7, PHP 8.2–8.4, PHPUnit 9.6, PHPStan Level 6, Vue-2-Style Admin-API (`Component.register`).

**Spec:** `docs/superpowers/specs/2026-10-25-folder-browser-design.md`

## Global Constraints

- Namespace `MuckiFacilityPlugin\`, **nicht** `LightsOn\`. Plugin-Stil: `protected`, kein `final`, Constructor Promotion, Muckiware-Dateikopf, `declare(strict_types=1);`.
- **Kein Autowiring.** Jeder neue Service/Controller in `src/Resources/config/services.xml`.
- Shopware 6.6 **und** 6.7 gleichzeitig. Template-Muster und Fallen: CLAUDE.md, Abschnitt „Shopware 6.6 und 6.7 parallel“. In neuem JS `this.$t(...)` statt `$tc` (Named-Values-Bug in 6.7).
- Der Browser ist **rein lesend**: keine Route legt Ordner an, benennt um oder loescht.
- Nur Ordner, keine Dateien, keine Dateiinhalte in API-Antworten.
- Ins Feld werden **immer absolute, logische Pfade** geschrieben (kein `realpath()`, Symlinks bleiben stehen).
- Backend-Verhalten fuer relative `repositoryPath`/`restorePath`/`backupPaths` bleibt **unveraendert**.
- Keine Startgrenze: sichtbar ist, was der PHP-Prozess lesen darf.
- Beide neuen Routes: `PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']`.
- Speichern wird durch die Dump-Pfad-Warnung **nie** verhindert.
- **Nicht committen, nicht pushen.** Der Maintainer sichtet den Diff und committet selbst. Keine Task enthaelt einen Commit-Schritt; Task-Grenzen sind Sichtungspunkte. Arbeit auf Branch `feat/folder-browser` (vom aktuellen `main` abzweigen).
- Version bleibt `v0.8.0` (noch nicht getaggt, steht unter `[Unreleased]`); CHANGELOG-Eintrag unter `[Unreleased]` → `### Added`.

## Verifizierte Kommandos

Vom Shop-Root `/Users/torstenfreyda/shopdev/sw6/sw67` aus. DDEV synchronisiert per Mutagen verzoegert — vor jedem Testlauf `ddev mutagen sync`.

```bash
ddev mutagen sync
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"
ddev exec "bin/build-administration.sh"
```

Neue Testdateien muessen als `<file>` in `phpunit.unit.xml` eingetragen werden (keine `<directory>`).

## Review Focus

1. **Neues Repository in noch nicht existierendem Ordner:** Feldwert `/mnt/backup/shop-neu` (letztes Segment fehlt) → Liste oeffnet in `/mnt/backup`, nicht mit Fehler. Test in Task 2 (`testListFallsBackToNearestExistingParent`).
2. **Ordner mit sehr vielen Unterordnern** (z. B. Media-Cache): Antwort ist gekuerzt, `truncated: true`, UI zeigt Hinweis statt zu haengen. Test in Task 2 (`testListIsTruncatedAtMaxEntries`), UI-Check in Task 4.
3. **Startpfad nicht lesbar** (z. B. `/root`): saubere 400-JSON-Antwort, kein 500; nicht lesbare Unterordner erscheinen mit `readable: false`. Tests in Task 2 und Task 3.
4. **Schnelles Tippen im Dump-Pfad:** spaete Antworten auf alte Werte duerfen die Warnung zum aktuellen Wert nicht ueberschreiben. Manueller Check in Task 4.
5. **User ohne Rolle editor:** kein Ordner-Symbol, kein Request, Feld wie heute; Route antwortet 403. ACL-Test in Task 3, manueller Check in Task 5.

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `src/Core/DumpPathRejection.php` (neu) | String-Enum der Ablehnungsgruende fuer einen Dump-Pfad |
| `src/Services/Settings.php` (aendern) | Regelpruefung aus `resolveOwnDumpPath()` als oeffentliche Methode herausloesen |
| `src/Services/SettingsInterface.php` (aendern) | neue Methode ins Interface |
| `src/Exception/InvalidBrowsePathException.php` (neu) | ungueltige Eingabe fuer den Browser |
| `src/Services/DirectoryBrowser.php` (neu) | Ordner listen, Dump-Pfad pruefen |
| `src/Controller/FilesystemController.php` (neu) | zwei API-Routes |
| `src/Resources/config/services.xml` (aendern) | Registrierung |
| `tests/Services/SettingsTest.php`, `tests/Services/DirectoryBrowserTest.php` (neu), `tests/Controller/FilesystemControllerTest.php` (neu), `tests/Controller/RouteAclTest.php` | Tests |
| `src/Resources/app/administration/src/module/muwa-backup-repository/component/muwa-backup-repository-path-field/` (neu: `index.js`, `.html.twig`, `.scss`) | Feld + Aufklapp-Liste + Dump-Hinweis |
| `.../muwa-backup-repository/index.js` (aendern) | Komponente registrieren |
| `.../snippet/de-DE.json`, `en-GB.json` (aendern) | Texte |
| `.../page/muwa-backup-repository-create/*.html.twig`, `.../page/muwa-backup-repository-detail/*.html.twig` (aendern) | Einbau |
| `src/Resources/public/administration/` | neu gebautes Admin-JS |
| `CHANGELOG.md`, `CLAUDE.md` | Doku |

---

### Task 1: Dump-Pfad-Regelpruefung aus `Settings` herausloesen

**Files:**
- Create: `src/Core/DumpPathRejection.php`
- Modify: `src/Services/Settings.php:140-210` (`resolveOwnDumpPath()`)
- Modify: `src/Services/SettingsInterface.php`
- Test: `tests/Services/SettingsTest.php`

**Interfaces:**
- Produces:
  - `enum DumpPathRejection: string` mit Faellen `ROOT_DIRECTORY = 'rootDirectory'`, `RELATIVE_SEGMENT = 'relativeSegment'`, `PROJECT_DIR_OR_PARENT = 'projectDirOrParent'`, `OVERLAPS_REPOSITORY_OR_RESTORE_PATH = 'overlapsRepositoryOrRestorePath'`, `BLOCKED_PATH = 'blockedPath'`.
  - `Settings::getOwnDumpPathRejection(?string $ownDumpPath, ?string $repositoryPath = null, ?string $restorePath = null): ?DumpPathRejection` (auch in `SettingsInterface`). `null` = Wert waere zulaessig **oder** leer (dann gilt der Default — kein Ablehnungsfall). Loggt nichts.
  - `Settings::resolveDumpPathValue(string $ownDumpPath): string` (auch in `SettingsInterface`, weil Task 2 sie fuer den Beschreibbarkeits-Check braucht): trimmt, entfernt abschliessende `/`, loest relative Werte gegen `kernel.project_dir` auf. `resolveOwnDumpPath()` nutzt sie intern ebenfalls.

- [ ] **Step 1: Failing Tests schreiben** in `SettingsTest` (Helper `createSettings()` wiederverwenden):
  - `testGetOwnDumpPathRejectionReturnsNullForEmptyValue` — `''`, `'   '`, `null` → `null`
  - `testGetOwnDumpPathRejectionReturnsReason` mit Data-Provider: `'/'` → `ROOT_DIRECTORY`; `'/var/db/../etc'` → `RELATIVE_SEGMENT`; `'/var/www'` → `PROJECT_DIR_OR_PARENT`; `'/etc/backup'` → `BLOCKED_PATH`; `'vendor/x'` → `BLOCKED_PATH`; (`'/mnt/repo/dump'`, repositoryPath `'/mnt/repo'`) → `OVERLAPS_REPOSITORY_OR_RESTORE_PATH`
  - `testGetOwnDumpPathRejectionReturnsNullForAllowedPath` — `'/mnt/backup/dump'` → `null`
  - `testGetOwnDumpPathRejectionDoesNotLog` — Logger-Mock `expects(never())->method('error')` bei `'/etc'`
  - `testResolveDumpPathValue` — `'var/db/x/'` → `'/var/www/html/var/db/x'`; `' /mnt/a '` → `'/mnt/a'`
- [ ] **Step 2: Tests laufen lassen** — Expected: FAIL (`Class "MuckiFacilityPlugin\Core\DumpPathRejection" not found` / undefined method).
- [ ] **Step 3: Implementieren.** `resolveOwnDumpPath()` ruft `getOwnDumpPathRejection()` und mappt den Grund auf die **bisherigen, unveraenderten** Log-Meldungen (Wortlaut beibehalten, die bestehenden Tests pruefen das teils). Die Reihenfolge der Pruefungen bleibt wie heute.
- [ ] **Step 4: Gesamte Unit-Suite + PHPStan** — Expected: alle bestehenden `testGetBackupPath*`-Tests weiter gruen, neue gruen, PHPStan ohne Fehler.

---

### Task 2: `Services\DirectoryBrowser`

**Files:**
- Create: `src/Exception/InvalidBrowsePathException.php` (Stil wie `InvalidBackupTypeException`, Default-Message `"Invalid path provided"`)
- Create: `src/Services/DirectoryBrowser.php`
- Modify: `src/Resources/config/services.xml` (Argumente: `MuckiFacilityPlugin\Services\SettingsInterface`, `kernel`)
- Modify: `phpunit.unit.xml`
- Test: `tests/Services/DirectoryBrowserTest.php`

**Interfaces:**
- Consumes: `SettingsInterface::getOwnDumpPathRejection()`, `SettingsInterface::resolveDumpPathValue()` (Task 1)
- Produces:
  - `const MAX_ENTRIES = 1000;`
  - `DirectoryBrowser::__construct(protected SettingsInterface $settings, protected KernelInterface $kernel)`
  - `listDirectories(?string $path): array` mit Form
    `['path' => string, 'parent' => ?string, 'directories' => list<array{name: string, path: string, readable: bool, hidden: bool, link: bool}>, 'truncated' => bool]`
  - `checkDumpPath(?string $dumpPath, ?string $repositoryPath, ?string $restorePath): array` mit Form
    `['rejection' => ?string, 'writable' => bool, 'resolvedPath' => ?string]` — `rejection` ist `DumpPathRejection->value` oder `null`; bei leerem Wert `['rejection' => null, 'writable' => true, 'resolvedPath' => null]`.
  - wirft `InvalidBrowsePathException` bei: Null-Byte oder Steuerzeichen (`/[\x00-\x1F\x7F]/`), Segment `.` oder `..`, Laenge > 4096, Startordner existiert, ist aber nicht lesbar.

Verhalten `listDirectories()`:
- leer/`null` → `kernel.project_dir`; relativ → gegen `kernel.project_dir`.
- nur lexikalisch normalisieren (mehrfache `/` zusammenziehen, abschliessenden `/` entfernen, `/` bleibt `/`). **Kein `realpath()`.**
- existiert der Pfad nicht als Ordner: Segment fuer Segment nach oben bis zu einem existierenden Ordner (endet spaetestens bei `/`).
- `parent` = Pfad ohne letztes Segment, `null` bei `/`.
- nur Eintraege, fuer die `is_dir()` gilt (auch Symlinks auf Ordner → `link: true` per `is_link()`), ohne `.`/`..`, sortiert per `strnatcasecmp`, `hidden` = Name beginnt mit `.`, `readable` per `is_readable()`.
- mehr als `MAX_ENTRIES` → abschneiden, `truncated: true`.

`checkDumpPath()`: `rejection` aus Task 1; `resolvedPath` aus `resolveDumpPathValue()`; `writable` = existiert der Ordner → `is_writable()`, sonst `is_writable()` des naechsten existierenden Elternordners. Legt nichts an.

- [ ] **Step 1: Failing Tests schreiben.** Echte Verzeichnisse unter `sys_get_temp_dir().'/muwa-browser-'.uniqid()` in `setUp()` anlegen, in `tearDown()` rekursiv entfernen (Rechte vorher zuruecksetzen). Kernel-Mock liefert dieses Verzeichnis als Projektverzeichnis; `SettingsInterface` als Mock.
  - `testListReturnsSortedSubdirectoriesOnly` — Ordner `b`, `A`, `c10`, `c2`, Datei `file.txt` → Namen `['A', 'b', 'c2', 'c10']`
  - `testListDefaultsToProjectDir` — `null` → `path` = Temp-Root
  - `testListResolvesRelativePathAgainstProjectDir` — `'A'` → `path` = `<root>/A`
  - `testListFallsBackToNearestExistingParent` — `'<root>/A/neu/tiefer'` → `path` = `<root>/A`
  - `testListNormalizesSlashes` — `'<root>//A/'` → `'<root>/A'`
  - `testListMarksHiddenDirectories` — `.git` → `hidden: true`
  - `testListKeepsSymlinkPathLogical` — Symlink `<root>/link` → `<root>/A`; `listDirectories('<root>/link')['path']` = `'<root>/link'`; im Root-Listing `link: true`
  - `testListMarksUnreadableDirectories` — `chmod 000` auf `<root>/locked` → `readable: false`; `markTestSkipped` wenn `posix_geteuid() === 0`
  - `testListThrowsForUnreadableStartDirectory` — `listDirectories('<root>/locked')` → `InvalidBrowsePathException` (gleiche Skip-Bedingung)
  - `testListIsTruncatedAtMaxEntries` — `MAX_ENTRIES + 5` Ordner → `count(directories) === MAX_ENTRIES`, `truncated: true`
  - `testListRejectsInvalidInput` mit Data-Provider: `"/tmp\0x"`, `"/tmp\nx"`, `'/tmp/../etc'`, `'/tmp/./x'`, `str_repeat('a', 4097)` → `InvalidBrowsePathException`
  - `testListRootHasNoParent` — `'/'` → `parent` = `null`
  - `testCheckDumpPathReturnsRejection` — Settings-Mock liefert `DumpPathRejection::BLOCKED_PATH` → `rejection` = `'blockedPath'`
  - `testCheckDumpPathReportsWritableForMissingDirectoryWithWritableParent` — `'<root>/A/neu'` → `writable: true`, Ordner existiert danach **nicht**
  - `testCheckDumpPathReportsNotWritable` — `chmod 0555` auf `<root>/ro` → `writable: false` (Skip bei root)
  - `testCheckDumpPathEmptyValue` — `''` → `['rejection' => null, 'writable' => true, 'resolvedPath' => null]`
- [ ] **Step 2: Tests laufen lassen** — Expected: FAIL (Klasse fehlt).
- [ ] **Step 3: Implementieren** gemaess Interfaces/Verhalten oben; Service in `services.xml` eintragen.
- [ ] **Step 4: Unit-Suite + PHPStan** — Expected: gruen.

---

### Task 3: `Controller\FilesystemController`

**Files:**
- Create: `src/Controller/FilesystemController.php`
- Modify: `src/Resources/config/services.xml` (`public="true"`, Argument `MuckiFacilityPlugin\Services\DirectoryBrowser`, `setContainer`-Call wie bei den anderen Controllern)
- Modify: `tests/Controller/RouteAclTest.php`, `phpunit.unit.xml`
- Test: `tests/Controller/FilesystemControllerTest.php`

**Interfaces:**
- Consumes: `DirectoryBrowser::listDirectories()`, `DirectoryBrowser::checkDumpPath()` (Task 2)
- Produces (fuer Task 4):
  - `POST /api/_action/muwa/filesystem/directories`, Name `api.action.muwa.filesystem.directories`, Methode `listDirectories(RequestDataBag $data, Context $context): JsonResponse`. Body `{ "path": string|null }` → 200 mit der Form aus `listDirectories()`.
  - `POST /api/_action/muwa/filesystem/check-dump-path`, Name `api.action.muwa.filesystem.check_dump_path`, Methode `checkDumpPath(RequestDataBag $data, Context $context): JsonResponse`. Body `{ "dbDumpPath", "repositoryPath", "restorePath" }` (je string|null) → 200 mit der Form aus `checkDumpPath()`.
  - Fehler: `InvalidBrowsePathException` → 400 `{ "errors": [{ "code": "MUWA_INVALID_PATH", "detail": <message> }] }`. Nicht-String-Werte im Body → wie `null` behandeln.
  - Beide mit `#[Route(defaults: ['_routeScope' => ['api']])]` auf Klassenebene und `PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']`.

- [ ] **Step 1: Failing Tests schreiben.**
  - `RouteAclTest::routeAclProvider()`: Eintraege `'filesystem directories' => [FilesystemController::class, 'listDirectories', ['muwa_backup_repository:update']]` und `'filesystem check dump path' => [FilesystemController::class, 'checkDumpPath', ['muwa_backup_repository:update']]`.
  - `FilesystemControllerTest` (DirectoryBrowser-Mock, `Context::createDefaultContext()`):
    - `testListDirectoriesReturnsBrowserResult` — Status 200, JSON = Mock-Rueckgabe, Mock mit `'/mnt'` aufgerufen
    - `testListDirectoriesReturns400OnInvalidPath` — Mock wirft `InvalidBrowsePathException('bad')` → 400, `errors[0].code` = `'MUWA_INVALID_PATH'`
    - `testListDirectoriesTreatsNonStringPathAsNull` — `path: ['x']` → Mock mit `null` aufgerufen
    - `testCheckDumpPathPassesAllThreeFields` — Mock erhaelt die drei Werte in Reihenfolge, 200
- [ ] **Step 2: Tests laufen lassen** — Expected: FAIL (Klasse fehlt; `testEveryRouteInControllerNamespaceIsCovered` waere ohne Provider-Eintrag ebenfalls rot).
- [ ] **Step 3: Implementieren** und in `services.xml` registrieren.
- [ ] **Step 4: Unit-Suite + PHPStan** — Expected: gruen.
- [ ] **Step 5: Smoke-Test gegen den laufenden Shop.** `ddev exec bin/console cache:clear`, dann mit einem Admin-Token (`POST /api/oauth/token`, `grant_type=password`, Admin-User aus der lokalen DDEV-Installation) `POST /api/_action/muwa/filesystem/directories` mit `{"path":"/var/www/html"}` → 200 mit `directories`; `{"path":"/tmp/../etc"}` → 400.

---

### Task 4: Admin-Komponente `muwa-backup-repository-path-field`

**Files:**
- Create: `src/Resources/app/administration/src/module/muwa-backup-repository/component/muwa-backup-repository-path-field/index.js`, `muwa-backup-repository-path-field.html.twig`, `muwa-backup-repository-path-field.scss`
- Modify: `.../muwa-backup-repository/index.js` — Registrierung wie bei `muwa-backup-repository-entity-path-select` (`Shopware.Component.register('muwa-backup-repository-path-field', () => import(...))`)
- Modify: `.../snippet/de-DE.json`, `en-GB.json`

**Interfaces:**
- Consumes: beide Routes aus Task 3 (Pfade ohne `/api`-Praefix, also `/_action/muwa/filesystem/directories`, `/_action/muwa/filesystem/check-dump-path`, wie die bestehenden `request*`-Daten in den Seiten).
- Produces (fuer Task 5):
  - Props: `value: String|null` (required), `label: String`, `placeholder: String`, `helpText: String`, `required: Boolean = false`, `maxlength: [String, Number] = 255`, `disabled: Boolean = false`, `name: String`, `checkDumpPath: Boolean = false`, `repositoryPath: String|null`, `restorePath: String|null`.
  - Event: `update:value` (String). Nutzung: `<muwa-backup-repository-path-field v-model:value="backupRepository.restorePath" ... />` — in 6.6 und 6.7 gleich, deshalb **kein** `isV6600`/`isV6500`-Paar mehr an den Einsatzorten.
  - Snippet-Namespace `muwa-backup-repository.pathField.*` mit Keys: `openBrowser`, `chooseFolder`, `parentFolder`, `noPermission` („keine Leserechte“), `empty` („Keine Unterordner“), `truncated` („Liste gekuerzt – Pfad weiter eintippen“), `loadError`, `isLink`, und `dumpPath.<rejection>` fuer jeden `DumpPathRejection`-Wert aus Task 1 sowie `dumpPath.notWritable`. Die Rejection-Texte sagen jeweils, dass das Backup stattdessen den Standardpfad `var/db/backup` nimmt.

Verhalten:
- Innen ein `sw-text-field` mit `:value`/`@update:value` und durchgereichten Props; Ordner-Symbol im `#suffix`-Slot (`sw-icon` `regular-folder`), nur wenn `acl.can('muwa_backup_repository.editor')` und nicht `disabled`.
- Oeffnen bei Fokus/Klick ins Feld und Klick aufs Symbol (nur mit Recht). Start-Request mit dem aktuellen Feldwert; Server liefert den naechsten existierenden Ordner.
- Liste: Pfadleiste (jedes Segment klickbar), Eintrag „..“ wenn `parent !== null`, Ordnerzeilen (blass bei `hidden`, Link-Kennzeichen bei `link`, ausgegraut + nicht klickbar + Hinweis `noPermission` bei `readable: false`), Lade-Spinner, Fehlerzeile (`errors[0].detail`), `truncated`-Hinweis, Footer-Button `chooseFolder` → emit `update:value` mit `path`, Liste schliessen.
- Tippen bei offener Liste: debounced (300 ms) neu laden mit dem getippten Wert.
- Schliessen bei Klick ausserhalb (Listener in `mounted`, in `beforeUnmount` **und** `beforeDestroy` entfernen — 6.6/6.7), bei `Escape`, nach Auswahl.
- Die Liste darf in der Grid-Zeile der Backup Paths nicht abgeschnitten werden: per `position: fixed` an der Feldposition (`getBoundingClientRect()`, bei Scroll/Resize neu berechnen) oder per `sw-popover`, falls es auf 6.7 sauber laeuft — Implementierer verifiziert auf sw67.
- `checkDumpPath === true`: Pruefung beim Erzeugen und bei Aenderung von `value`, `repositoryPath`, `restorePath` (debounced 400 ms, unabhaengig von der Liste, nur mit Recht). Jede Anfrage bekommt eine laufende Nummer; Antworten mit veralteter Nummer werden verworfen. Unter dem Feld ein Warn-Hinweis (Warnfarbe, kein Fehlerzustand des Felds): `dumpPath.<rejection>` wenn `rejection`, sonst `dumpPath.notWritable` wenn `writable === false`, sonst nichts. Request-Fehler → Hinweis ausblenden, keine Notification.
- HTTP wie in den Seiten: `Shopware.Application.getContainer('init').httpClient` mit eigenem `getApiHeader()` (gleiche Header wie `muwa-backup-repository-create/index.js:263`).

- [ ] **Step 1: Komponente, Registrierung und Snippets anlegen.**
- [ ] **Step 2: Admin bauen** — `ddev mutagen sync && ddev exec "bin/build-administration.sh"`. Expected: Build ohne Fehler, neue Dateien unter `src/Resources/public/administration/assets/`.
- [ ] **Step 3: Isoliert pruefen.** Vorlaeufig auf der Detail-Seite **nur** das Feld Database dump path ersetzen (Task 5 macht den Rest) und im Browser (sw67, Admin, Repository oeffnen, Tab Configuration) pruefen:
  - Klick ins Feld oeffnet Liste unter dem Feld; Navigation per Ordner, „..“ und Pfadleiste; „Diesen Ordner waehlen“ schreibt den absoluten Pfad
  - Feldwert mit nicht existierendem letzten Segment → Liste startet im Elternordner
  - `/root` bzw. ein `chmod 000`-Ordner erscheint ausgegraut
  - `/var/www/html/vendor` eintippen → Warnhinweis „blockedPath“ erscheint; zuruecksetzen → verschwindet
  - **Review Focus 4:** schnell `/etc`, dann `/mnt/backup-x` tippen → am Ende kein Warnhinweis (oder nur der zum letzten Wert)
  - **Review Focus 2:** einen Ordner mit > 1000 Unterordnern oeffnen (z. B. per `mkdir -p /tmp/many/{1..1100}` im Container) → Hinweis „Liste gekuerzt“
  - Escape und Klick ausserhalb schliessen die Liste; Konsole ohne Vue-Warnungen

---

### Task 5: Einbau auf Anlegen- und Detail-Seite

**Files:**
- Modify: `.../page/muwa-backup-repository-create/muwa-backup-repository-create.html.twig` (Repository Path ab Zeile ~100, Restore Path ~176, Database dump path ~195, Grid-Spalte `column-backupPath` im Backup-Paths-Card ~216ff.)
- Modify: `.../page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig` (Restore Path ~212, Database dump path ~231, Grid-Spalte `column-backupPath` ~302)
- Modify: `src/Resources/public/administration/` (Build)

**Interfaces:**
- Consumes: `muwa-backup-repository-path-field` (Task 4)

- [ ] **Step 1: Ersetzen.** Jeweils das `isV6600`/`isV6500`-Paar von `sw-text-field` durch **eine** `muwa-backup-repository-path-field` mit denselben Props (`label`, `placeholder`, `helpText`, `required`, `maxlength`, `name`) ersetzen. Database dump path auf beiden Seiten mit `:check-dump-path="true"`, `:repository-path="backupRepository.repositoryPath"`, `:restore-path="backupRepository.restorePath"`. Der **gesperrte** Repository Path der Detail-Seite (Zeilen ~191–209) bleibt unveraendert.
- [ ] **Step 2: Admin bauen** — wie Task 4 Step 2.
- [ ] **Step 3: Manuell pruefen (sw67):**
  - Anlegen-Seite: Browser an Repository Path, Restore Path, Database dump path und jeder neu hinzugefuegten Backup-Paths-Zeile; Repository anlegen mit per Browser gewaehltem Elternordner + von Hand ergaenztem Namen → Init laeuft durch
  - Detail-Seite: Browser an Restore Path, Database dump path, jeder Backup-Paths-Zeile; Repository Path gesperrt **ohne** Symbol; Speichern funktioniert, gespeicherte Werte stimmen
  - Liste in der Backup-Paths-Zeile wird nicht vom Grid abgeschnitten
  - Detail-Seite mit gespeichertem ungueltigen Dump-Pfad (`/etc/x`) oeffnen → Warnhinweis sofort sichtbar; Restore Path auf den Dump-Pfad aendern → Ueberschneidungs-Hinweis
  - **Review Focus 5:** Admin-User mit Rolle nur viewer → kein Ordner-Symbol, keine Requests an `/filesystem/*` im Network-Tab, Felder wie bisher
- [ ] **Step 4:** Falls eine 6.6-Instanz verfuegbar ist, Step 3 dort wiederholen; sonst im Abschlussbericht ausdruecklich als „nur auf 6.7 getestet“ vermerken.

---

### Task 6: Doku

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]` → `### Added`)
- Modify: `CLAUDE.md`

- [ ] **Step 1: CHANGELOG** — Eintrag: Ordner-Browser an Repository Path (nur Anlegen), Restore Path, Database dump path und Backup Paths; rein lesend, zeigt was der PHP-Prozess lesen darf; Live-Warnung beim Dump-Pfad (abgelehnt / nicht beschreibbar); zwei neue Routes mit Recht `muwa_backup_repository:update`.
- [ ] **Step 2: CLAUDE.md** — Tabelle „API-Routes“ um beide Routes ergaenzen; im Abschnitt „Administration“ die Komponente `muwa-backup-repository-path-field` kurz beschreiben; im Abschnitt „Backup-Pfad“ erwaehnen, dass `getOwnDumpPathRejection()` die gemeinsame Regelquelle fuer Backup und Admin-Warnung ist und Regeln nur dort geaendert werden.
- [ ] **Step 3: Abschluss** — Unit-Suite + PHPStan ein letztes Mal gruen; `git status` zeigt nur erwartete Dateien; nichts committen.
