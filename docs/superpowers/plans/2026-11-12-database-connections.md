# Eigene Datenbankverbindungen fuer Datenbank-Backups — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Backup-Konfigurationen koennen statt der Systemdatenbank eine eigene, in einer separaten Tabelle gepflegte Datenbankverbindung dumpen; Aufloesung von Verbindung und Dumper laeuft ueber ein Event, das den Datenbanktyp mitliefert.

**Architecture:** Neue DAL-Entity `muwa_database_connection` mit abgesichertem Passwort (`PasswordSource`, eigener `DatabasePasswordResolver` mit `MUWA_DB_`-/`var/secrets/`-Einschraenkung). `muwa_backup_repository.database_connection_id` (nullable, `NULL` = Standardverbindung). `Services\DatabaseConnectionResolver` loest `ResolveDatabaseConnectionEvent` aus; der eingebaute `MysqlDatabaseConnectionSubscriber` liefert `ResolvedDatabaseConnection` + `MysqlDatabaseDumper`. `BackupRunnerFactory` reicht das Ergebnis (`DatabaseBackupTarget`) an die beiden DB-Runner; Standardverbindung = heutiges Verhalten, eigene Verbindung = Abbruch des ganzen Laufs bei jedem Fehler. Administration: zweiter Tab mit Liste/Create/Detail + Pruefen-Button, Dropdown in der Backup-Konfiguration.

**Tech Stack:** PHP 8.2–8.4, Shopware 6.6 + 6.7 (DBAL 3/4), `spatie/db-dumper ^3.7`, Symfony EventDispatcher, Vue Options-API (`Component.register`), PHPUnit ueber `phpunit.unit.xml`.

**Spec:** `docs/superpowers/specs/2026-11-12-database-connections-design.md`

## Global Constraints

- **Git: nur Code bearbeiten, keine Git-Schreiboperationen.** Kein `git commit`, kein `git push`, kein `git add`, kein neuer Branch, kein Worktree, kein `git stash`/`reset`/`checkout`. Gearbeitet wird direkt im bereits ausgecheckten Branch `main` des Plugin-Repos; alle Aenderungen bleiben im Working Tree. Commit und Push macht der Nutzer spaeter selbst. Das gilt auch fuer Subagents und Reviewer — sie pruefen per `git diff` gegen `HEAD`.
- Namespace `MuckiFacilityPlugin\`; Stil wie Bestand (`protected`, kein `final`/`readonly`, `declare(strict_types=1)`, Datei-Header-Kommentar wie in den Nachbardateien).
- Jeder Service in `src/Resources/config/services.xml` — kein Autowiring. Jeder neue Unit-Test als `<file>` in `phpunit.unit.xml`.
- Tests laufen mit: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"` (Einzeltest: `--filter <Name>`).
- PHPStan: `composer run-script phpstan` (Level 6, `src/`) muss gruen bleiben.
- Shopware 6.6 **und** 6.7: nie `new \Doctrine\DBAL\Exception`, Spalten-Checks in Migrationen per `$this->columnExists()`.
- Typ-Konstante: `mysql` (Anzeige "MySQL / MariaDB"); `type` ist ein freier String (max. 32), damit Plugins eigene Typen anlegen.
- Standardverbindung = `database_connection_id IS NULL`; ihr Verhalten (inkl. stillem Loggen bei `CannotStartDump`/`DumpFailed`, Weitermachen pro Tabelle) bleibt **exakt** wie heute.
- Eigene Verbindung: jeder Fehler wirft `DatabaseBackupFailedException` → bricht ueber den bestehenden `throw $e` in `Backup::startBackupRunner()` den ganzen Lauf ab. Nie Fallback auf `DATABASE_URL`.
- DB-Passwort: Pflicht, nie in API-Antworten, Logs, Exceptions, Messages oder Prozess-Argumenten. `password`/`password_source` ohne `ApiAware`, `WriteProtected(Context::SYSTEM_SCOPE)`.
- Erlaubte Quellen fuer DB-Passwoerter: `encrypted`, `env` (Name beginnt mit `MUWA_DB_`), `file` (nach `realpath()` unterhalb `<projectDir>/var/secrets/`). Kein `plain`.
- Repository-Passwoerter (`RepositoryPasswordResolver`, `SecretCipher::KEY_CONTEXT`) bleiben unveraendert.
- ACL-Privilegien: `muwa_database_connection:read|update|create|delete`, eingehaengt in die bestehenden Rollen von `muwa_backup_repository`.

## Review Focus

1. **Bestehende Konfiguration nach dem Update** (`database_connection_id` fehlt/`NULL`) — Dump laeuft ueber `DATABASE_URL` mit `useSingleTransaction()`, Tabellenliste ueber `CoreDatabase`, Dump-Fehler werden nur geloggt. Test in Task 4 (`testDefaultConnectionKeepsLegacyErrorBehaviour`).
2. **Eigene Verbindung, Passwort-Datei geloescht / Env-Variable leer** — Backup bricht ab, Meldung nennt Verbindungsname, kein Dump landet in restic. Test in Task 4 (`testOwnConnectionResolveFailureAbortsBackupRun`).
3. **`file`-Quelle per Symlink oder `..` aus `var/secrets/` heraus** — abgewiesen beim Speichern und beim Aufloesen. Test in Task 2 (`testFileSourceSymlinkOutsideSecretsDirIsRejected`).
4. **Fehlermeldung eines fehlgeschlagenen Verbindungstests enthaelt das Passwort** (z. B. aus einer DBAL-/mysqldump-Meldung) — Passwort wird maskiert. Test in Task 3 (`testConnectionErrorMessageNeverContainsPassword`).
5. **Detail-Seite speichert mit leerem Passwortfeld** — gespeichertes Passwort bleibt erhalten. Test in Task 5 (`testSaveWithEmptyPasswordKeepsStoredPassword`).

---

### Task 1: Schema, Entity und Durchreichen der Verbindungs-ID

**Files:**
- Create: `src/Migration/Migration1794470400.php` (Tabelle + FK-Spalte, idempotent)
- Create: `src/Core/Content/DatabaseConnection/DatabaseConnectionDefinition.php`, `...Entity.php`, `...Collection.php`
- Create: `src/Core/DatabaseTypes.php`
- Modify: `src/Core/Content/BackupRepository/BackupRepositoryDefinition.php` (FkField + ManyToOne), `BackupRepositoryEntity.php`
- Modify: `src/Entity/BackupRepositorySettings.php`, `src/Services/Backup.php::prepareCreateBackup()`, `src/Controller/InitBackupRepositoryController.php::createRepositoryInitInputs()`, `src/Services/Content/BackupRepository.php::createPayload()`
- Modify: `src/MuckiFacilityPlugin.php::dropPluginTables()`, `services.xml` (Definition-Tag)
- Test: `tests/Core/Content/DatabaseConnectionDefinitionTest.php`, Erweiterung `tests/Core/Content/BackupRepositoryDefinitionTest.php`, `tests/Services/BackupTest.php`, `tests/MuckiFacilityPluginUninstallTest.php`

**Interfaces:**
- Produces: `DatabaseConnectionDefinition::ENTITY_NAME = 'muwa_database_connection'`; Entity-Getter `getName(): string`, `getType(): string`, `getHost(): string`, `getPort(): ?int`, `getDatabaseName(): string`, `getUsername(): string`, `getPassword(): string`, `getPasswordSource(): string` (+ Setter).
- Produces: `DatabaseTypes::MYSQL = 'mysql'`, `DatabaseTypes::DEFAULT_PORTS = ['mysql' => 3306]`.
- Produces: `BackupRepositorySettings::getDatabaseConnectionId(): ?string` / `setDatabaseConnectionId(?string): void`, Property mit Default `null`.
- Produces: `BackupRepositoryEntity::getDatabaseConnectionId(): ?string`, Association `databaseConnection`.

- [ ] **Step 1: Failing tests schreiben**
  - `DatabaseConnectionDefinitionTest::testPasswordIsNotReadableThroughTheApi` — Feld `password` ohne `ApiAware` (Muster `BackupRepositoryDefinitionTest::testRepositoryPasswordIsNotReadableThroughTheApi`).
  - `testPasswordAndSourceAreWriteProtectedToSystemScope` — beide Felder `WriteProtected` mit `Context::SYSTEM_SCOPE`.
  - `testPasswordColumnIsLongEnoughForCiphertext` — `password` max. 512.
  - `BackupRepositoryDefinitionTest::testDatabaseConnectionIdIsOptionalForeignKey` — `FkField` `databaseConnectionId` existiert, **ohne** `Required`.
  - `BackupTest::testPrepareCreateBackupCarriesDatabaseConnectionId` — Entity mit ID `X` → Settings liefern `X`; Entity ohne → `null`.
  - `BackupTest::testNewSettingsDefaultToStandardConnection` — `(new BackupRepositorySettings())->getDatabaseConnectionId()` ist `null` (kein `\Error`).
  - `MuckiFacilityPluginUninstallTest::testDatabaseConnectionTableIsDroppedAfterBackupRepository` — Reihenfolge der `DROP TABLE`-Statements: `muwa_backup_repository` vor `muwa_database_connection`.
- [ ] **Step 2: Tests laufen lassen — FAIL** (Klassen/Felder fehlen)
- [ ] **Step 3: Implementieren**
  - Migration `update()`: `CREATE TABLE IF NOT EXISTS muwa_database_connection` (Spalten exakt wie Spec, `password VARCHAR(512) NOT NULL`, `password_source VARCHAR(16) NOT NULL`, `custom_fields JSON NULL`, `created_at DATETIME(3) NOT NULL`, `updated_at DATETIME(3) NULL`, utf8mb4); danach `if (!$this->columnExists(...,'muwa_backup_repository','database_connection_id'))` → `ADD COLUMN database_connection_id BINARY(16) NULL DEFAULT NULL` + `ADD CONSTRAINT fk.muwa_backup_repository.database_connection_id FOREIGN KEY ... REFERENCES muwa_database_connection(id) ON DELETE RESTRICT ON UPDATE CASCADE`. Keine Daten-Updates.
  - Definition: Felder analog Spec; `password` `StringField(..., 512)` mit `removeFlag(ApiAware::class)` + `WriteProtected(SYSTEM_SCOPE)`, `passwordSource` `WriteProtected(SYSTEM_SCOPE)`, `CustomFields`, `OneToManyAssociationField('backupRepositories', BackupRepositoryDefinition::class, 'database_connection_id')`.
  - `prepareCreateBackup()`, `createRepositoryInitInputs()` (`databaseConnectionId` aus Request, leer/ungueltige UUID → `null`) und `createPayload()` reichen die ID durch.
- [ ] **Step 4: Tests — PASS**, PHPStan gruen
- [ ] **Step 5: Migration im Dev-Shop pruefen**
  Run: `ddev exec bin/console database:migrate MuckiFacilityPlugin --all` zweimal, danach `ddev mysql -e "SELECT COUNT(*) FROM muwa_backup_repository WHERE database_connection_id IS NOT NULL"`
  Expected: zweiter Lauf ohne Fehler, Ergebnis `0`.
- [ ] **Step 6: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `feat: add database connection entity and optional link on backup repositories`

---

### Task 2: `DatabasePasswordResolver` mit Exfiltrationsschutz

**Files:**
- Create: `src/Services/Content/DatabasePasswordResolver.php`
- Create: `src/Exception/UnresolvableDatabasePasswordException.php`
- Modify: `services.xml`, `phpunit.unit.xml`
- Test: `tests/Services/Content/DatabasePasswordResolverTest.php`

**Interfaces:**
- Consumes: `SecretCipher`, `PasswordSource`, `KernelInterface::getProjectDir()`.
- Produces: `DatabasePasswordResolver::resolve(string $storedValue, PasswordSource $source): string` und `prepareForStorage(string $inputValue, PasswordSource $source): string`; Konstanten `ENV_PREFIX = 'MUWA_DB_'`, `SECRETS_DIRECTORY = '/var/secrets'`. Wirft `UnresolvableDatabasePasswordException` (Meldungen ohne Passwort).

- [ ] **Step 1: Failing tests** (Muster `RepositoryPasswordResolverTest`, Projektverzeichnis = Temp-Dir mit `var/secrets/`)
  - `testEncryptedSourceRoundTrip`
  - `testPlainSourceIsRejected` — `PasswordSource::PLAIN` → Exception in `resolve` und `prepareForStorage`.
  - `testEnvSourceRequiresPrefix` — `APP_SECRET` → Exception; `MUWA_DB_SHOP` gesetzt → Wert.
  - `testFileSourceInsideSecretsDirIsResolved` — Datei `var/secrets/db.pw` mit `"pw\n"` → `pw`.
  - `testFileSourceOutsideSecretsDirIsRejected` — `/etc/hosts`, `var/secrets/../../composer.json`.
  - `testFileSourceSymlinkOutsideSecretsDirIsRejected` — Symlink in `var/secrets/` auf Datei ausserhalb → Exception.
  - `testEmptyPasswordIsRejected`
  - `testExceptionMessagesNeverContainThePassword`
- [ ] **Step 2: FAIL**
- [ ] **Step 3: Implementieren** — Verschluesselung ueber `SecretCipher::encrypt/decrypt` (gleicher Cipher, Repository-Pfad unberuehrt). Pfadpruefung: `realpath($path)` muss mit `realpath($projectDir.'/var/secrets').'/'` beginnen. Pruefung in `resolve()` **und** `prepareForStorage()`.
- [ ] **Step 4: PASS**
- [ ] **Step 5: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `feat: add database password resolver restricted to MUWA_DB_ env vars and var/secrets`

---

### Task 3: Event, Dumper-Interface, MySQL-Subscriber und Resolver-Service

**Files:**
- Create: `src/Events/ResolveDatabaseConnectionEvent.php`
- Create: `src/Entity/ResolvedDatabaseConnection.php`, `src/Entity/DatabaseBackupTarget.php`
- Create: `src/Backup/Database/DatabaseDumperInterface.php`, `src/Backup/Database/MysqlDatabaseDumper.php`
- Create: `src/Subscriber/MysqlDatabaseConnectionSubscriber.php`
- Create: `src/Services/DatabaseConnectionResolver.php`
- Create: `src/Exception/DatabaseBackupFailedException.php`
- Modify: `services.xml`, `phpunit.unit.xml`
- Test: `tests/Services/DatabaseConnectionResolverTest.php`, `tests/Subscriber/MysqlDatabaseConnectionSubscriberTest.php`, `tests/Backup/Database/MysqlDatabaseDumperTest.php`

**Interfaces:**
- Consumes: Task 1 (`DatabaseConnectionEntity`, `DatabaseTypes`, `BackupRepositorySettings::getDatabaseConnectionId()`), Task 2 (`DatabasePasswordResolver`), `SettingsInterface::getDatabaseUrl()`, `CoreDatabase::getListOfAllTables()`.
- Produces:
  - `ResolveDatabaseConnectionEvent::NAME = 'mucki_facility.database_connection.resolve'`; Konstruktor `(string $type, ?DatabaseConnectionEntity $databaseConnection, ?BackupRepositorySettings $backupSettings, Context $context)`; Getter dazu; `setResolvedConnection(ResolvedDatabaseConnection)`, `getResolvedConnection(): ?ResolvedDatabaseConnection`, `setDumper(DatabaseDumperInterface)`, `getDumper(): ?DatabaseDumperInterface`, `isResolved(): bool`, `isDefaultConnection(): bool` (= Verbindung `null`).
  - `ResolvedDatabaseConnection` (Konstruktor: `string $type, string $host, ?int $port, string $databaseName, string $username, string $password, array $extra = [], ?string $databaseUrl = null`) mit Gettern; `getPassword()` nur intern verwenden; `__debugInfo()` maskiert das Passwort.
  - `DatabaseDumperInterface`: `listTables(ResolvedDatabaseConnection $c): array<int,string>`, `dump(ResolvedDatabaseConnection $c, string $dumpFile, array $tables = []): void`, `testConnection(ResolvedDatabaseConnection $c): void`. Fehler → `DatabaseBackupFailedException` (Meldung ohne Passwort).
  - `DatabaseBackupTarget` (Konstruktor `ResolvedDatabaseConnection $connection, DatabaseDumperInterface $dumper, bool $isDefault, string $connectionName`).
  - `DatabaseConnectionResolver::resolveForBackup(BackupRepositorySettings $s): DatabaseBackupTarget` und `resolveConnection(?DatabaseConnectionEntity $c, Context $context): DatabaseBackupTarget`. Wirft `DatabaseBackupFailedException`.
  - Standardverbindung: `connectionName = 'default'`, Typ aus Schema von `DATABASE_URL` (`mysql`, `mariadb`, `pdo-mysql` → `mysql`).

- [ ] **Step 1: Failing tests**
  - Resolver: `testNullConnectionDispatchesEventWithMysqlTypeAndIsDefault`; `testUnknownConnectionIdThrows` (Repository liefert nichts, Meldung enthaelt die ID); `testUnhandledTypeThrows` — Typ `postgres` ohne Subscriber → Meldung `No database connection handler for type "postgres"`; `testHigherPrioritySubscriberOverridesConnectionAndDumper` (echter `EventDispatcher`, zweiter Listener mit Prioritaet 100 setzt eigenen Dumper → dieser wird zurueckgegeben).
  - Subscriber: `testIgnoresOtherTypes`; `testDefaultConnectionUsesDatabaseUrl` (`ResolvedDatabaseConnection::getDatabaseUrl()` = `DATABASE_URL`); `testOwnConnectionResolvesPasswordAndDefaultPort` (Port `null` → 3306); `testUnresolvablePasswordThrowsWithConnectionName`.
  - Dumper: `testDefaultConnectionDumpUsesDatabaseUrlAndSingleTransaction` (spatie-Dumper ueber eine `protected createDumper(): MySql`-Fabrikmethode mocken); `testOwnConnectionDumpSetsHostPortNameUserPassword`; `testDefaultListTablesUsesCoreDatabase`; `testConnectionErrorMessageNeverContainsPassword` (DBAL-Exception mit Passwort im Text → geworfene Meldung enthaelt es nicht, Passwort durch `***` ersetzt).
- [ ] **Step 2: FAIL**
- [ ] **Step 3: Implementieren**
  - Subscriber reagiert auf `ResolveDatabaseConnectionEvent::NAME` mit Prioritaet 0, nur bei `type === DatabaseTypes::MYSQL`, setzt Verbindung + `MysqlDatabaseDumper`.
  - `MysqlDatabaseDumper`: Standardverbindung → `setDatabaseUrl()` wie heute + `listTables()` ueber `CoreDatabase`; eigene → `setHost/setPort/setDbName/setUserName/setPassword` (spatie nutzt eine Credentials-Datei, das Passwort erscheint nicht in `ps`), `useSingleTransaction()`. `listTables()`/`testConnection()` fuer eigene Verbindung ueber kurzlebige `DriverManager::getConnection([...'driver' => 'pdo_mysql'])`, danach `close()`. spatie-/DBAL-Fehler → `DatabaseBackupFailedException` mit maskierter Meldung.
  - Resolver laedt die Entity ueber `muwa_database_connection.repository` im System-Scope.
- [ ] **Step 4: PASS**, PHPStan gruen
- [ ] **Step 5: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `feat: resolve database connection and dumper through ResolveDatabaseConnectionEvent`

---

### Task 4: Runner auf `DatabaseBackupTarget` umstellen

**Files:**
- Modify: `src/Backup/BackupRunnerFactory.php`, `src/Backup/Database/CompleteFileRunner.php`, `src/Backup/Database/CompleteFilesRunner.php`, `services.xml`
- Test: `tests/Backup/BackupRunnerFactoryTest.php`, `tests/Backup/Database/CompleteFileRunnerTest.php`, `tests/Backup/Database/CompleteFilesRunnerTest.php`, `tests/Services/BackupTest.php`

**Interfaces:**
- Consumes: Task 3 (`DatabaseConnectionResolver::resolveForBackup()`, `DatabaseBackupTarget`, `DatabaseDumperInterface`).
- Produces: `BackupRunnerFactory::__construct(LoggerInterface, SettingsInterface, ServicesCliOutput, DatabaseConnectionResolver)` (`CoreDatabase` wandert in den Dumper); DB-Runner-Konstruktoren `(LoggerInterface, SettingsInterface, BackupRepositorySettings, DatabaseBackupTarget, DumpArchiver)`.

- [ ] **Step 1: Failing tests**
  - Factory: `testDatabaseRunnersReceiveResolvedTarget`; `testFilesRunnerDoesNotResolveConnection` (Resolver wird bei `files` nie aufgerufen).
  - `CompleteFileRunner`: `testDefaultConnectionKeepsLegacyErrorBehaviour` — Dumper wirft, `isDefault=true` → kein Throw, Fehler geloggt; `testOwnConnectionDumpFailureThrows` — `isDefault=false` → `DatabaseBackupFailedException`, Meldung enthaelt Verbindungsname; Dateiname enthaelt weiterhin den Datenbanknamen.
  - `CompleteFilesRunner`: `testUsesTableListOfTargetDumper` — Tabellen kommen aus `$dumper->listTables()`, nicht aus `CoreDatabase`; `testOwnConnectionAbortsAtFirstFailingTable` — zweite von drei Tabellen schlaegt fehl → Throw, dritte wird nie gedumpt; `testDefaultConnectionContinuesAfterFailingTable` — wie heute weiter.
  - `BackupTest::testOwnConnectionResolveFailureAbortsBackupRun` — Resolver wirft → `createBackup()` wirft, `FilesRunner` fuer den Dump-Ordner wird nicht gestartet, kein `AfterBackupCreatedEvent`.
  - Bestehende Runner-Tests auf neue Konstruktoren anpassen (Dateinamen-Tests unveraendert gruen).
- [ ] **Step 2: FAIL**
- [ ] **Step 3: Implementieren** — Factory ruft `resolveForBackup()` nur fuer die beiden DB-Typen. Runner: `$target->getDumper()->dump($target->getConnection(), $dumpFile[, [$table]])`; `catch (DatabaseBackupFailedException $e)`: Standardverbindung → loggen wie heute (gleiche Meldungstexte `Cannot start dump:`/`Dump failed:`), eigene → weiterwerfen.
- [ ] **Step 4: PASS** (gesamte Suite), PHPStan gruen
- [ ] **Step 5: Regressionscheck im Dev-Shop** — bestehendes Repository (ohne Verbindung) per `ddev exec bin/console muckiware:backup:create <id>` sichern
  Expected: Dump-Datei `<datum>_<dbname>.backup.sql[.tar.gz]` im Snapshot wie vorher (`restic ls` bzw. Snapshot-Tab).
- [ ] **Step 6: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `feat: dump the configured database connection in database backup runners`

---

### Task 5: API — Speichern und Pruefen

**Files:**
- Create: `src/Controller/DatabaseConnectionController.php`
- Create: `src/Services/Content/DatabaseConnectionRepository.php`
- Modify: `services.xml`, `tests/Controller/RouteAclTest.php`, `phpunit.unit.xml`
- Test: `tests/Controller/DatabaseConnectionControllerTest.php`

**Interfaces:**
- Consumes: Task 1–3.
- Produces:
  - `POST /api/_action/muwa/database-connection/save` (Name `api.action.muwa.database_connection.save`, ACL `['muwa_database_connection:update']`; bei neuer ID zusaetzlich `$context->isAllowed('muwa_database_connection:create')`, sonst 403). Body: `id`, `name`, `type`, `host`, `port`, `databaseName`, `username`, `passwordSource`, `password`. Antwort `{success: true, id}`.
  - `POST /api/_action/muwa/database-connection/test` (Name `api.action.muwa.database_connection.test`, ACL `['muwa_database_connection:update']`). Body: entweder nur `id` oder Formularwerte wie oben (+ optional `id` fuer gespeichertes Passwort). Antwort `{success: bool, message: string}`, HTTP 200 auch bei fehlgeschlagener Verbindung.
  - `DatabaseConnectionRepository::save(array $input, Context $context): string`, `getById(string $id): ?DatabaseConnectionEntity`, `buildTransient(array $input, ?DatabaseConnectionEntity $stored): DatabaseConnectionEntity`.
- Validierung (400 `MUWA_INVALID_DATABASE_CONNECTION`): `name`, `host`, `databaseName`, `username` nicht leer; `type` nicht leer, max. 32; `port` leer oder 1–65535; `passwordSource` ∈ {`encrypted`,`env`,`file`}; Passwort Pflicht bei neuer Verbindung.

- [ ] **Step 1: Failing tests**
  - `testSaveCreatesConnectionWithEncryptedPassword` — gespeicherter Wert beginnt mit `v1:`.
  - `testSaveWithEmptyPasswordKeepsStoredPassword`
  - `testSaveRejectsEnvWithoutPrefix` — 400.
  - `testSaveNewConnectionRequiresCreatePrivilege` — nur `update` → 403.
  - `testTestRouteWithUnsavedValuesDispatchesResolver` / `testTestRouteReturnsMaskedErrorMessage`.
  - `testResponsesNeverContainPassword` — weder Save- noch Test-Antwort.
  - `RouteAclTest`: beide neuen Routen mit exakt obiger ACL eintragen.
- [ ] **Step 2: FAIL**
- [ ] **Step 3: Implementieren** — Schreiben im System-Scope (Muster `BackupRepository::createBackupRepository()`), Passwort ueber `DatabasePasswordResolver::prepareForStorage()`. Test-Route: `buildTransient()` → `DatabaseConnectionResolver::resolveConnection()` → `getDumper()->testConnection()`.
- [ ] **Step 4: PASS**
- [ ] **Step 5: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `feat: add API routes to save and test database connections`

---

### Task 6: Administration

**Files:**
- Create: `src/Resources/app/administration/src/module/muwa-backup-repository/page/muwa-database-connection-list/` (in den Listen-Tab eingebettete Komponente), `page/muwa-database-connection-detail/` (Create + Detail in einer Seite, `isNew` per Route)
- Create: `component/muwa-database-connection-select/` (Dropdown fuer die Backup-Konfiguration)
- Modify: `module/muwa-backup-repository/index.js` (Routen `database-connection/create`, `database-connection/detail/:id`), `acl/index.js`, Listen-Seite (zweiter Tab `databaseConnectionList`), Create- und Detail-Seite des Repositories (Dropdown neben `dbDumpPath`), `snippet/de-DE.json`, `snippet/en-GB.json`
- Test: `tests/Administration/DatabaseConnectionAdministrationTest.php` (Datei-Inhaltstests wie `RestoreDirectoryTreeAdministrationTest`)

**Interfaces:**
- Consumes: Task 5 (Routen), DAL `muwa_database_connection` (lesen/loeschen), `muwa_backup_repository.databaseConnectionId`.
- Produces: Snippet-Namespace `muwa-backup-repository.databaseConnection.*`; Tab-Key `databaseConnectionList`; Dropdown-Wert `null` = Standardverbindung (Label `muwa-backup-repository.databaseConnection.defaultConnection`: "Standardverbindung (System)" / "Default connection (system)").

- [ ] **Step 1: Failing tests**
  - `testListPageHasDatabaseConnectionTab`
  - `testSelectOffersDefaultConnectionAsFirstOptionWithNullValue`
  - `testSelectOnlyShownForDatabaseBackupTypes` (`v-if` gegen `noneDatabase`)
  - `testAclMapsDatabaseConnectionPrivilegesIntoExistingRoles` (read/update/create/delete in viewer/editor/creator/deleter)
  - `testSnippetsExistInBothLanguages` (gleiche Keys in de-DE und en-GB)
  - `testDetailPageNeverBindsStoredPassword` (Passwortfeld bindet nicht an `entity.password`)
- [ ] **Step 2: FAIL**
- [ ] **Step 3: Implementieren**
  - Liste: `sw-entity-listing` mit Name, Typ, Host, Datenbank; Loeschen: vorher `muwa_backup_repository` mit `EqualsFilter('databaseConnectionId', id)` abfragen, bei Treffern Fehler-Notification mit den Repository-Namen und kein Delete.
  - Detail: Felder wie Spec, Typ-Select (Optionen mit `id`/`name` **und** `value`/`label`, zwei Zweige `isV6700` / `isV6600 && !isV6700` laut Plugin-`CLAUDE.md`), Passwort-UI wie Repository-Create-Seite, Button "Verbindung pruefen" → Test-Route, Ergebnis als Notification. Speichern ueber Save-Route.
  - Dropdown: Optionen aus DAL + fuehrender Eintrag `null`; Create-Seite sendet `databaseConnectionId` an die Init-Route, Detail-Seite speichert ueber das normale `repository.save`.
- [ ] **Step 4: PASS**, Build: `ddev exec bin/build-administration.sh`
- [ ] **Step 5: Browser-Check** (`https://muckidirve.ddev.site/admin`, 6.7): Verbindung zur DDEV-DB (`db`, 3306, `db`, `db`, Passwort per `encrypted`) anlegen → "Verbindung pruefen" ok; falscher Host → Fehlermeldung ohne Passwort; Repository auf die Verbindung stellen, Backup starten, Dump im Snapshot; Verbindung mit Nutzung loeschen → blockiert mit Namensliste; neues Repository ohne Auswahl → `databaseConnectionId` `NULL`.
- [ ] **Step 6: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `feat: manage database connections and select them in backup configuration`

---

### Task 7: Dokumentation und Version

**Files:**
- Modify: `CHANGELOG.md` (in den offenen, noch nicht getaggten Abschnitt `[0.9.0]` unter `### Added`; `composer.json` steht bereits auf `v0.9.0`, kein weiterer Bump), `README.md` (Abschnitt Datenbankverbindungen: `MUWA_DB_`-Praefix, `var/secrets/`, Event-Erweiterung mit Beispiel-Subscriber-Skizze), `CLAUDE.md` (Entities-Tabelle, API-Routes, Backup-Pfad: Event + Fehlersemantik)

- [ ] **Step 1: Dokumentation schreiben**
- [ ] **Step 2: Gesamte Suite + PHPStan**
  Run: Tests wie in Global Constraints, `composer run-script phpstan`
  Expected: alle gruen
- [ ] **Step 3: Nicht committen** — Aenderungen im Working Tree lassen. Vorschlag fuer die spaetere Commit-Message des Nutzers: `docs: document database connections`
