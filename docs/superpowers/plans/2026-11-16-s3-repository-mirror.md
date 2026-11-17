# S3-Spiegelung von Backup-Repositories – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nach jedem Backup mit gruenem `restic check` wird das lokale restic-Repository automatisch
per eigener Message in einen S3-Bucket gespiegelt; Verbindungen zentral, Status sichtbar,
Flow-Builder-Events fuer Erfolg/Misserfolg.

**Architecture:** Neue Entities `muwa_s3_connection` und `muwa_backup_repository_uploads`, drei
`mirror_s3_*`-Spalten am Repository. Ein reiner Sync-Planer (lokal vs. remote, Schluessel + Groesse)
plus ein `RepositoryMirror`-Service, der ueber `aws/aws-sdk-php` hochlaedt/loescht, Historie schreibt
und Events feuert. `Services\Backup::createBackup()` dispatcht nach dem Check eine
`MirrorRepositoryMessage`; Handler und CLI rufen denselben Service.

**Tech Stack:** PHP 8.3+, Shopware 6.6/6.7 (DAL, Messenger, Flow Builder), `aws/aws-sdk-php ^3.369`,
Vue-2-Style-Administration, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-16-s3-repository-mirror-design.md` (freigegeben) — Executor
liest Spec und Plan.

## Global Constraints

- Namespace `MuckiFacilityPlugin\`; Plugin-Stil beibehalten (kein `final`/`readonly`-Zwang, `protected` Properties, `declare(strict_types=1)`).
- Jeder neue Service in `src/Resources/config/services.xml` (kein Autowiring); Commands mit `console.command`, Handler zusaetzlich `messenger.message_handler`-Tag.
- Muss in Shopware 6.6 **und** 6.7 laufen (Regeln in Plugin-`CLAUDE.md`, Abschnitt "6.6 und 6.7 parallel").
- Secret Access Key nie in Logs, Exceptions, API-Antworten, Messages, Historie oder Event-Daten.
- Secret-Quellen: `encrypted` / `env` (nur Praefix `MUWA_S3_`) / `file` (nur unter `<projectDir>/var/secrets/`), kein `plain`.
- Remote nur Schluessel im restic-Layout verwalten: `config`, `keys/`, `data/`, `index/`, `snapshots/`; `locks/` nie; `config` nie loeschen.
- "Check gruen" = Status enthaelt `no errors were found` (case-insensitive) — eine zentrale PHP-Methode.
- Historie: max. **100** Eintraege je Repository.
- Region: Pflicht bei Provider `aws`, sonst Default `us-east-1`.
- Path-Style: `true` bei `minio`/`ceph`/`other`, `false` bei `aws`.
- Mirror-Lock-Name: `mucki-facility.mirror.<repositoryId>`; Backup-Lock: `mucki-facility.backup`.
- Event-Namen: `mucki_facility.repository_upload.succeeded` / `mucki_facility.repository_upload.failed`.
- Spaltenname `trigger_source` (nicht `trigger`).
- **Nicht committen.** Der Nutzer sichtet den Diff und committet selbst. "Commit"-Schritte heissen hier "Stand pruefen".
- Tests nur per `ddev exec`, vorher `ddev mutagen sync` (Host-Edits sonst verzoegert im Container):
  `ddev mutagen sync && ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml --filter <Name>"`.
  Neue Unit-Testdateien in `phpunit.unit.xml` eintragen.

## Review Focus

1. **Bucket enthaelt fremde/alte Objekte unter dem Prefix** (z.B. `README.txt`, `locks/x`, `data/` eines anderen Tools) → bleiben unberuehrt. Test: Task 4 `testForeignAndLockKeysAreNeverTouched`.
2. **Lokales Repository-Verzeichnis fehlt / ist leer / nicht gemountet** → `failed`, kein einziger Remote-Delete. Test: Task 6 `testMissingLocalRepositoryNeverDeletes`.
3. **Netzwerkfehler mitten im Upload** → kein Loeschen, Eintrag `failed` mit Zaehlerstand, naechster Lauf setzt fort. Test: Task 6 `testUploadErrorSkipsDeletesAndRecordsProgress`.
4. **SDK-Fehlermeldung enthaelt Secret oder Signatur** → gespeicherte Meldung ohne Secret. Test: Task 6 `testSecretNeverReachesHistoryOrEvent`.
5. **Prefix-Eingaben wie `/backups//shop/`, `backups`, `../x`, leer** → normalisiert `backups/shop/`, `backups/`, abgewiesen, `""`. Test: Task 4 `testPrefixNormalization` + Task 7 `testRejectsDotDotPrefix`.

---

### Task 1: Datenmodell (Migration, Entities, Uninstall)

**Files:**
- Create: `src/Migration/Migration1794830400.php`
- Create: `src/Core/Content/S3Connection/S3ConnectionDefinition.php`, `S3ConnectionEntity.php`, `S3ConnectionCollection.php`
- Create: `src/Core/Content/BackupRepository/Uploads/BackupRepositoryUploadsDefinition.php`, `BackupRepositoryUploadsEntity.php`, `BackupRepositoryUploadsCollection.php`
- Modify: `src/Core/Content/BackupRepository/BackupRepositoryDefinition.php`, `BackupRepositoryEntity.php`
- Modify: `src/MuckiFacilityPlugin.php` (`dropPluginTables()`), `src/Resources/config/services.xml`
- Test: `tests/Core/Content/S3ConnectionDefinitionTest.php`, `tests/Core/Content/BackupRepositoryUploadsDefinitionTest.php`, `tests/Core/Content/BackupRepositoryDefinitionTest.php`, `tests/MuckiFacilityPluginUninstallTest.php`

**Interfaces:**
- Produces: Entity `muwa_s3_connection` (Felder `name`, `provider`, `endpoint`, `region`, `accessKeyId`, `secretAccessKey`, `secretSource`, `customFields`, Association `backupRepositories`), Entity `muwa_backup_repository_uploads` (Felder `backupRepositoryId`, `status`, `triggerSource`, `bucket`, `prefix`, `s3ConnectionName`, `startedAt`, `finishedAt`, `filesUploaded`, `filesDeleted`, `filesUnchanged`, `bytesUploaded`, `message`), am Repository `mirrorS3ConnectionId`, `mirrorS3Bucket`, `mirrorS3Prefix`, Associations `mirrorS3Connection` (ManyToOne), `uploads` (OneToMany, `CascadeDelete`).

- [ ] **Step 1: Failing Tests schreiben** (Stil wie `DatabaseConnectionDefinitionTest`)

```php
public function testAllConnectionFieldsAreWriteProtectedAndSecretIsNotApiAware(): void
{
    $fields = $this->definition()->getFields();
    foreach (['name','provider','endpoint','region','accessKeyId','secretAccessKey','secretSource','customFields'] as $name) {
        self::assertTrue($fields->get($name)->is(WriteProtected::class), $name);
    }
    self::assertFalse($fields->get('secretAccessKey')->is(ApiAware::class));
    self::assertTrue($fields->get('backupRepositories')->is(RestrictDelete::class));
}
// BackupRepositoryDefinitionTest:
public function testMirrorFieldsAreWriteProtected(): void
{ foreach (['mirrorS3ConnectionId','mirrorS3Bucket','mirrorS3Prefix'] as $n) { self::assertTrue($fields->get($n)->is(WriteProtected::class)); } }
// BackupRepositoryUploadsDefinitionTest:
public function testTriggerColumnIsNotReservedWord(): void
{ self::assertSame('trigger_source', $fields->get('triggerSource')->getStorageName()); }
// Uninstall-Test: Reihenfolge
self::assertLessThan(array_search('muwa_backup_repository', $dropped), array_search('muwa_backup_repository_uploads', $dropped));
self::assertLessThan(array_search('muwa_s3_connection', $dropped), array_search('muwa_backup_repository', $dropped));
```

- [ ] **Step 2: Tests laufen lassen** → FAIL (Klassen fehlen).
- [ ] **Step 3: Migration `Migration1794830400`** (Timestamp `1794830400`), idempotent wie `Migration1794470400`: `CREATE TABLE IF NOT EXISTS muwa_s3_connection` (Spalten/Typen exakt laut Spec "Neue Tabelle `muwa_s3_connection`", `secret_access_key VARCHAR(512) NULL`), `CREATE TABLE IF NOT EXISTS muwa_backup_repository_uploads` (laut Spec, FK `ON DELETE CASCADE`, Index auf `(backup_repository_id, created_at)`), Spalten am Repository per `columnExists()` mit FK `fk.muwa_backup_repository.mirror_s3_connection_id` `ON DELETE RESTRICT ON UPDATE CASCADE`.
- [ ] **Step 4: Definitions/Entities/Collections** nach Muster `DatabaseConnection*` bzw. `Stats*`; Definitions in `services.xml` mit `shopware.entity.definition`-Tag; `dropPluginTables()`: `BackupRepositoryUploadsDefinition::ENTITY_NAME` vor `BackupRepositoryDefinition`, `S3ConnectionDefinition::ENTITY_NAME` danach.
- [ ] **Step 5: Tests** → PASS; zusaetzlich `ddev exec bin/console database:migrate --all MuckiFacilityPlugin` laeuft fehlerfrei, zweiter Lauf ebenso.
- [ ] **Step 6: Stand pruefen** (`git diff --stat`), nicht committen.

---

### Task 2: Provider-Enum, Secret-Resolver, Check-Regel

**Files:**
- Create: `src/Core/S3Providers.php`, `src/Core/CheckStatus.php`
- Create: `src/Services/Content/S3SecretResolver.php`, `src/Exception/UnresolvableS3SecretException.php`
- Test: `tests/Services/Content/S3SecretResolverTest.php`, `tests/Core/S3ProvidersTest.php`, `tests/Core/CheckStatusTest.php`

**Interfaces:**
- Produces:
  - `enum S3Providers: string { AWS='aws'; MINIO='minio'; CEPH='ceph'; OTHER='other'; public function usesPathStyle(): bool; public function requiresRegion(): bool; public function requiresEndpoint(): bool; }`
  - `final class CheckStatus { public static function isHealthy(?string $status): bool }`
  - `S3SecretResolver::resolve(string $storedValue, PasswordSource $source): string`, `prepareForStorage(string $input, PasswordSource $source): string`; Konstanten `ENV_PREFIX = 'MUWA_S3_'`, `SECRETS_DIRECTORY = '/var/secrets'`. Wirft `UnresolvableS3SecretException`.

- [ ] **Step 1: Failing Tests** (Faelle 1:1 aus `DatabasePasswordResolverTest` uebernehmen, Praefix angepasst)

```php
public function testEnvRequiresS3Prefix(): void
{ $this->expectException(UnresolvableS3SecretException::class); $this->resolver()->resolve('MUWA_DB_SECRET', PasswordSource::ENV); }
public function testPlainIsRejected(): void { /* PLAIN -> Exception */ }
public function testFileOutsideSecretsDirIsRejected(): void { /* '/etc/passwd' -> Exception */ }
public function testLineBreakAndNulAreRejected(): void { /* "a\nb", "a\0b" */ }
public function testExceptionMessageNeverContainsSecret(): void
{ try { $r->prepareForStorage("geheim\n", PasswordSource::ENCRYPTED); } catch (UnresolvableS3SecretException $e) { self::assertStringNotContainsString('geheim', $e->getMessage()); } }
// S3ProvidersTest
self::assertFalse(S3Providers::AWS->usesPathStyle()); self::assertTrue(S3Providers::MINIO->usesPathStyle());
self::assertTrue(S3Providers::AWS->requiresRegion()); self::assertFalse(S3Providers::AWS->requiresEndpoint());
self::assertTrue(S3Providers::OTHER->requiresEndpoint());
// CheckStatusTest
self::assertTrue(CheckStatus::isHealthy('no errors were found'));
self::assertTrue(CheckStatus::isHealthy('No Errors Were Found'));
self::assertFalse(CheckStatus::isHealthy('Fatal: repository contains errors'));
self::assertFalse(CheckStatus::isHealthy(null));
```

- [ ] **Step 2:** Tests → FAIL.
- [ ] **Step 3: Implementieren.** Resolver als eigene Klasse nach Muster `DatabasePasswordResolver` (gemeinsame Basisklasse optional laut Spec; `DatabasePasswordResolverTest` muss unveraendert gruen bleiben). In `services.xml` registrieren.
- [ ] **Step 4:** Tests + `DatabasePasswordResolverTest` → PASS.
- [ ] **Step 5:** Stand pruefen.

---

### Task 3: S3-Verbindungen speichern und testen (Service, Client-Factory, Controller)

**Files:**
- Create: `src/Services/Content/S3ConnectionRepository.php`, `src/Exception/InvalidS3ConnectionException.php`
- Create: `src/Services/Mirror/S3ClientFactory.php`, `src/Services/Mirror/ErrorSanitizer.php`
- Create: `src/Controller/S3ConnectionController.php`
- Modify: `composer.json` (`"aws/aws-sdk-php": "^3.369"` in `require`), `services.xml`
- Test: `tests/Services/Content/S3ConnectionRepositoryTest.php`, `tests/Services/Mirror/S3ClientFactoryTest.php`, `tests/Services/Mirror/ErrorSanitizerTest.php`, `tests/Controller/S3ConnectionControllerTest.php`, `tests/Controller/RouteAclTest.php`

**Interfaces:**
- Consumes: Task 1 Entities, Task 2 `S3Providers`, `S3SecretResolver`.
- Produces:
  - `S3ConnectionRepository::getById(string $id): ?S3ConnectionEntity`, `save(array $input, Context $context): string` (gibt ID zurueck), `buildTransient(array $input, ?S3ConnectionEntity $stored): S3ConnectionEntity`, `resolveSecret(S3ConnectionEntity $connection): string`.
  - `S3ClientFactory::create(S3ConnectionEntity $connection, string $secret): \Aws\S3\S3Client` (`version => 'latest'`, `region` (Default `us-east-1`), `endpoint` falls gesetzt, `use_path_style_endpoint` aus Provider, `credentials` aus Key-ID + Secret).
  - `ErrorSanitizer::sanitize(\Throwable $error, string ...$secrets): string` — bei `AwsException`: `getAwsErrorCode() . ': ' . getAwsErrorMessage()`; sonst `get_class() . ': ' . getMessage()`; danach jedes nicht-leere Secret durch `***` ersetzen; max. 1000 Zeichen.
  - Routes: `POST /api/_action/muwa/s3-connection/save` (ACL `muwa_s3_connection:update`, Neuanlage zusaetzlich `:create` im Controller geprueft) → `{success:true,id}`; `POST /api/_action/muwa/s3-connection/test` (ACL `muwa_s3_connection:update`, Body wie save + optional `bucket`) → mit Bucket `HeadBucket`, ohne `ListBuckets`; 400 `MUWA_INVALID_S3_CONNECTION` bei ungueltigen Feldern, 200 `{success:false,message}` bei Zugangsfehler (bereinigte Meldung), 200 `{success:true}` sonst.

Validierung in `save`/`buildTransient`: `name`, `provider` (Enum), `accessKeyId` Pflicht; `endpoint` Pflicht wenn `requiresEndpoint()`, nur `https://`, bei `minio`/`other` auch `http://`; `region` Pflicht wenn `requiresRegion()`; Zeilenumbruch/NUL in jedem Feld → `InvalidS3ConnectionException`. Aenderung von `provider`/`endpoint`/`region`/`accessKeyId`/`secretSource` ohne neues Secret → Fehler (wie `DatabaseConnectionRepository::targetChanged()`). Schreiben im System-Scope.

- [ ] **Step 1: Failing Tests**

```php
// S3ConnectionRepositoryTest
public function testTargetChangeWithoutSecretIsRejected(): void { /* stored endpoint A, input endpoint B, secret '' -> InvalidS3ConnectionException */ }
public function testAwsRequiresRegion(): void {}
public function testOtherRequiresEndpoint(): void {}
public function testHttpEndpointOnlyForMinioAndOther(): void { /* aws + http:// -> Exception; minio + http:// -> ok */ }
// S3ClientFactoryTest
self::assertTrue($client->getConfig('use_path_style_endpoint'));               // minio
self::assertSame('us-east-1', $client->getRegion());                            // region leer, minio
// ErrorSanitizerTest
public function testSecretIsMasked(): void
{ self::assertStringNotContainsString('S3CR3T', ErrorSanitizer::sanitize(new \RuntimeException('bad S3CR3T'), 'S3CR3T')); }
public function testAwsExceptionUsesCodeAndMessage(): void { /* AwsException mit Code AccessDenied -> beginnt mit 'AccessDenied: ' */ }
// S3ConnectionControllerTest (Stil DatabaseConnectionControllerTest)
public function testCreateRequiresCreatePrivilege(): void {}
public function testTestRouteReturnsSanitizedFailure(): void { /* MockHandler -> 403, Antwort success=false, ohne Secret */ }
// RouteAclTest: beide Routen mit ACL eingetragen, keine neue Public-Route.
```

- [ ] **Step 2:** Tests → FAIL.
- [ ] **Step 3:** Implementieren; Controller nach `DatabaseConnectionController`; `S3ClientFactory` bekommt in Tests per optionalem `handler`-Parameter (`create(..., ?callable $handler = null)`) einen `Aws\MockHandler`.
- [ ] **Step 4:** Tests → PASS.
- [ ] **Step 5:** Stand pruefen.

---

### Task 4: Sync-Planer und Schluesselregeln (rein, ohne SDK)

**Files:**
- Create: `src/Services/Mirror/MirrorKeyRules.php`, `src/Services/Mirror/MirrorPlanner.php`, `src/Entity/MirrorPlan.php`
- Create: `src/Services/Mirror/LocalRepositoryScanner.php`
- Test: `tests/Services/Mirror/MirrorKeyRulesTest.php`, `tests/Services/Mirror/MirrorPlannerTest.php`, `tests/Services/Mirror/LocalRepositoryScannerTest.php`

**Interfaces:**
- Produces:
  - `MirrorKeyRules::normalizePrefix(?string $prefix): string` (leer → `''`, sonst ohne fuehrenden, mit einem abschliessenden `/`, doppelte `/` zusammengezogen; `.`/`..`-Segment → `\InvalidArgumentException`)
  - `MirrorKeyRules::isManaged(string $relativeKey): bool` (`config` oder beginnt mit `keys/`, `data/`, `index/`, `snapshots/`)
  - `MirrorKeyRules::uploadRank(string $relativeKey): int` (data 0, index 1, keys 2, snapshots 3, config 4)
  - `MirrorPlanner::plan(array $local, array $remote): MirrorPlan` — beide `array<string relativeKey, int size>`, `$remote` ungefiltert
  - `MirrorPlan` mit `getUploads(): list<string>` (nach `uploadRank` sortiert, innerhalb Rank alphabetisch), `getDeletes(): list<string>` (Reihenfolge snapshots, index, keys, data), `getUnchangedCount(): int`
  - `LocalRepositoryScanner::isResticRepository(string $path): bool` (`config`-Datei + `keys/` nicht leer), `hasActiveLocks(string $path): bool` (mind. eine Datei in `locks/`), `scan(string $path): array<string,int>` (rekursiv, nur regulaere Dateien, Symlinks uebersprungen, `locks/` ausgelassen, Trenner `/`)

- [ ] **Step 1: Failing Tests**

```php
#[DataProvider('prefixes')] public function testPrefixNormalization(?string $in, string $out): void
{ self::assertSame($out, MirrorKeyRules::normalizePrefix($in)); }
public static function prefixes(): array
{ return [[null,''],['',''],['/',''],['backups','backups/'],['/backups//shop/','backups/shop/']]; }
public function testDotDotPrefixIsRejected(): void { $this->expectException(\InvalidArgumentException::class); MirrorKeyRules::normalizePrefix('a/../b'); }

public function testUploadsMissingAndSizeChangedInResticOrder(): void
{
    $plan = (new MirrorPlanner())->plan(
        ['config'=>10,'keys/k'=>5,'data/aa/1'=>100,'index/i'=>7,'snapshots/s'=>3],
        ['config'=>10,'data/aa/1'=>99]
    );
    self::assertSame(['data/aa/1','index/i','keys/k','snapshots/s'], $plan->getUploads());
    self::assertSame(1, $plan->getUnchangedCount());
}
public function testDeletesOnlyManagedKeysInReverseOrderAndNeverConfig(): void
{
    $plan = (new MirrorPlanner())->plan(['config'=>1,'keys/k'=>1], ['config'=>1,'keys/k'=>1,'data/x'=>1,'index/y'=>1,'snapshots/z'=>1]);
    self::assertSame(['snapshots/z','index/y','data/x'], $plan->getDeletes());
}
public function testForeignAndLockKeysAreNeverTouched(): void
{
    $plan = (new MirrorPlanner())->plan(['config'=>1], ['config'=>1,'README.txt'=>1,'locks/l'=>1,'other/data/x'=>1]);
    self::assertSame([], $plan->getDeletes());
}
// Scanner (Temp-Verzeichnis)
public function testScanSkipsLocksAndSymlinks(): void {}
public function testEmptyKeysDirIsNotARepository(): void {}
public function testLockFileIsDetected(): void {}
```

- [ ] **Step 2:** → FAIL. **Step 3:** Implementieren (`config` ist Rank 4 und landet nie in `getDeletes()`). **Step 4:** → PASS. **Step 5:** Stand pruefen.

---

### Task 5: Flow-Builder-Events

**Files:**
- Create: `src/Events/AbstractRepositoryUploadEvent.php`, `src/Events/RepositoryUploadSucceededEvent.php`, `src/Events/RepositoryUploadFailedEvent.php`
- Create: `src/Subscriber/RepositoryUploadBusinessEventSubscriber.php`, `src/Entity/MirrorResult.php`
- Modify: `services.xml`, Snippets `src/Resources/app/administration/src/module/muwa-backup-repository/snippet/{de-DE,en-GB}.json` (`sw-flow.triggers.mucki_facility`, `repository_upload`, `succeeded`, `failed`)
- Test: `tests/Events/RepositoryUploadEventTest.php`, `tests/Subscriber/RepositoryUploadBusinessEventSubscriberTest.php`

**Interfaces:**
- Produces:
  - `MirrorResult` (Getter): `getStatus(): string` (`succeeded|failed|skipped`), `getTriggerSource(): string`, `getBucket(): ?string`, `getPrefix(): ?string`, `getS3ConnectionName(): ?string`, `getStartedAt(): \DateTimeImmutable`, `getFinishedAt(): \DateTimeImmutable`, `getFilesUploaded(): int`, `getFilesDeleted(): int`, `getFilesUnchanged(): int`, `getBytesUploaded(): int`, `getMessage(): ?string`; Konstruktor mit benannten Argumenten.
  - `AbstractRepositoryUploadEvent implements FlowEventAware, MailAware, ScalarValuesAware`, Konstruktor `(string $backupRepositoryId, string $backupRepositoryName, MirrorResult $result, Context $context)`; `getValues()` liefert genau: `backupRepositoryId`, `backupRepositoryName`, `status`, `bucket`, `prefix`, `s3ConnectionName`, `startedAt`, `finishedAt` (ISO 8601), `filesUploaded`, `filesDeleted`, `bytesUploaded`, `message`; `getMailStruct()` → `new MailRecipientStruct([])`; `getSalesChannelId()` → `null`; statisches `getAvailableData()` mit `ScalarValueType` je Key.
  - `RepositoryUploadSucceededEvent::EVENT_NAME = 'mucki_facility.repository_upload.succeeded'`, `RepositoryUploadFailedEvent::EVENT_NAME = 'mucki_facility.repository_upload.failed'`; `getName()` gibt die Konstante zurueck (kein Konstruktor-Zustand — `BusinessEventCollector::define()` nutzt `newInstanceWithoutConstructor()`).
  - Subscriber auf `BusinessEventCollectorEvent::NAME`: `define()` beider Klassen, Ergebnis in `$event->getCollection()->set(...)`.

- [ ] **Step 1: Failing Tests**

```php
public function testValuesContainNoSecretKeys(): void
{ self::assertSame(['backupRepositoryId','backupRepositoryName','status','bucket','prefix','s3ConnectionName','startedAt','finishedAt','filesUploaded','filesDeleted','bytesUploaded','message'], array_keys($event->getValues())); }
public function testNameWorksWithoutConstructor(): void
{ $e = (new \ReflectionClass(RepositoryUploadFailedEvent::class))->newInstanceWithoutConstructor();
  self::assertSame('mucki_facility.repository_upload.failed', $e->getName()); }
public function testSubscriberRegistersBothEvents(): void { /* BusinessEventCollector-Mock define() -> Collection hat beide Namen */ }
```

- [ ] **Step 2–4:** FAIL → implementieren → PASS (zusaetzlich mit Bootstrap: `BusinessEventCollector::collect()` enthaelt beide Namen). **Step 5:** Stand pruefen.

---

### Task 6: `RepositoryMirror`-Service und Upload-Historie

**Files:**
- Create: `src/Services/RepositoryMirror.php`, `src/Services/Content/BackupRepositoryUploads.php`
- Modify: `src/Services/Content/BackupRepositoryChecks.php` (`saveNewCheck()` gibt die neue ID zurueck; `getById(string $checkId): ?BackupRepositoryChecksEntity`), `services.xml`, Snippets (`notification.mirrorFailed`, `notification.mirrorSkipped`)
- Test: `tests/Services/RepositoryMirrorTest.php`, `tests/Services/Content/BackupRepositoryUploadsTest.php`

**Interfaces:**
- Consumes: Tasks 2–5.
- Produces:
  - `RepositoryMirror::mirror(string $backupRepositoryId, string $triggerSource, ?string $checkId, bool $ignoreCheck = false): ?MirrorResult`
    - `null` (nichts geschrieben, kein Event) wenn: Repository nicht gefunden, keine Verbindung gesetzt, Mirror-Lock belegt.
    - Gate: `$ignoreCheck === false` → Check per `$checkId` (oder neuester Check, wenn `null`); `!CheckStatus::isHealthy()` → `skipped`.
    - Ablauf exakt Spec "Spiegelungs-Algorithmus" Schritte 1–7 inkl. 2a (Remote-`config` per `GetObject` byteweise vergleichen).
    - Upload: Dateien `>= 64 MiB` per `Aws\S3\ObjectUploader`, sonst `PutObject`; Deletes per `DeleteObjects` in Batches à 1000; `ListObjectsV2` paginiert (`getPaginator`).
    - Lock per `LockFactory::createLock('mucki-facility.mirror.' . $id, 3600)`, alle 50 Dateien `refresh()`.
    - Jedes Ergebnis ≠ `null`: `BackupRepositoryUploads::save()`, Event (`succeeded` → Succeeded, sonst Failed), bei `failed`/`skipped` zusaetzlich `AdminNotification` (`status: 'error'`, Snippet `muwa-backup-repository.notification.mirrorFailed` bzw. `…mirrorSkipped`, Parameter `name`, `message`). Wirft nie (alles in `try/catch \Throwable`, Meldung via `ErrorSanitizer` mit Secret).
  - `BackupRepositoryUploads::save(string $backupRepositoryId, MirrorResult $result): void` (danach alle bis auf die neuesten 100 des Repositories loeschen), `getLatest(string $backupRepositoryId): ?BackupRepositoryUploadsEntity`, `getLatestSucceeded(string $backupRepositoryId): ?BackupRepositoryUploadsEntity`.

- [ ] **Step 1: Failing Tests** (S3 per `Aws\MockHandler` mit Request-Protokoll, lokales Repo als Temp-Verzeichnis)

```php
public function testHappyPathUploadsThenDeletesAndRecordsSucceeded(): void
{ /* Erwartete Operationsfolge: ListObjectsV2, GetObject(config), PutObject*, DeleteObjects; status succeeded; Event Succeeded dispatcht */ }
public function testRedCheckSkipsWithoutAnyS3Call(): void { /* status skipped, MockHandler-Queue unberuehrt, Event Failed mit status=skipped */ }
public function testMissingLocalRepositoryNeverDeletes(): void { /* Pfad leer -> failed, keine DeleteObjects */ }
public function testActiveLockAbortsWithoutRemoteChange(): void {}
public function testLockAppearingBeforeDeletePhaseSkipsDeletes(): void {}
public function testDifferentRemoteConfigAborts(): void { /* GetObject liefert anderen Inhalt -> failed, keine Put/Delete */ }
public function testUploadErrorSkipsDeletesAndRecordsProgress(): void { /* 3. PutObject -> 500; filesUploaded=2; keine DeleteObjects */ }
public function testSecretNeverReachesHistoryOrEvent(): void { /* Exception-Text enthaelt Secret -> message ohne Secret */ }
public function testBusyMirrorLockReturnsNull(): void {}
public function testNoConnectionReturnsNull(): void {}
// BackupRepositoryUploadsTest
public function testKeepsOnlyLatestHundredEntries(): void {}
```

- [ ] **Step 2:** → FAIL. **Step 3:** Implementieren; Methoden ≤ 30 Zeilen, Schritte als eigene `protected`-Methoden. **Step 4:** → PASS. **Step 5:** Stand pruefen.

---

### Task 7: Spiegel-Ziel am Repository speichern (Route + Ueberlappung)

**Files:**
- Create: `src/Controller/BackupRepositoryMirrorController.php`, `src/Services/Mirror/MirrorTargetValidator.php`
- Modify: `src/Services/Content/BackupRepository.php` (`updateMirrorTarget(string $id, array $payload): bool` im System-Scope, Muster `updateBackupRepositoryHooks`), `services.xml`
- Test: `tests/Controller/BackupRepositoryMirrorControllerTest.php`, `tests/Services/Mirror/MirrorTargetValidatorTest.php`, `tests/Controller/RouteAclTest.php`

**Interfaces:**
- Consumes: Task 3 `S3ConnectionRepository::getById`, Task 4 `MirrorKeyRules::normalizePrefix`.
- Produces:
  - Route `PUT /api/_action/muwa/backup/{backupRepositoryId}/mirror`, ACL `muwa_backup_repository:update`, Body `{s3ConnectionId: ?string, bucket: ?string, prefix: ?string}`; `s3ConnectionId = null` → alle drei Felder `null` (Spiegelung aus). Erfolg 204, Fehler 400 `{errors:[{code,detail}]}` mit `MUWA_INVALID_ID`, `MUWA_INVALID_MIRROR_TARGET`, `MUWA_MIRROR_TARGET_OVERLAP` (detail enthaelt Namen der kollidierenden Konfiguration), 404 wenn Repository fehlt.
  - `MirrorTargetValidator::assertValid(array $input): array` (Bucket: 3–63 Zeichen, `^[a-z0-9][a-z0-9.-]*[a-z0-9]$`, kein `..`, keine IP-Form; Verbindung existiert; Prefix normalisiert) und `findOverlap(string $repositoryId, string $connectionId, string $bucket, string $prefix): ?string` (Name der kollidierenden Konfiguration). Gleiches Ziel = gleicher normalisierter Endpoint (lowercase, ohne abschliessenden `/`; `aws` ohne Endpoint = `aws:<region>`) + gleicher Bucket, und ein Prefix ist gleich dem anderen oder beginnt mit ihm (leerer Prefix ueberlappt alles).

- [ ] **Step 1: Failing Tests**

```php
public function testRejectsDotDotPrefix(): void { /* prefix 'a/../b' -> 400 MUWA_INVALID_MIRROR_TARGET */ }
public function testRejectsInvalidBucket(): void { /* 'My_Bucket' */ }
#[DataProvider('overlaps')] public function testOverlap(string $existingPrefix, string $newPrefix, bool $overlaps): void {}
public static function overlaps(): array
{ return [['shop/','shop/',true],['','shop/',true],['shop/','shop/db/',true],['shop/','shopware/',false]]; }
public function testSameEndpointViaDifferentConnectionsOverlaps(): void {}
public function testNullConnectionClearsAllFields(): void {}
```

- [ ] **Step 2–4:** FAIL → implementieren → PASS. **Step 5:** Stand pruefen.

---

### Task 8: Automatische Spiegelung nach dem Backup (Message + Handler)

**Files:**
- Create: `src/MessageQueue/Message/MirrorRepositoryMessage.php`, `src/MessageQueue/Handler/MirrorRepositoryHandler.php`
- Modify: `src/Services/Backup.php` (`createCheckItem()` gibt `?string` Check-ID zurueck; nach `createCheckItem()` Dispatch, wenn `mirrorS3ConnectionId` gesetzt), `services.xml` (Backup bekommt `messenger.default_bus` als **letztes** Konstruktor-Argument; Handler mit Tag), `tests/Services/BackupTest.php`, `tests/Services/LifecycleEventDispatchTest.php` (Konstruktor-Aufrufe ergaenzen)
- Test: `tests/MessageQueue/Handler/MirrorRepositoryHandlerTest.php`, `tests/Services/BackupTest.php`

**Interfaces:**
- Consumes: Task 6 `RepositoryMirror::mirror`.
- Produces: `MirrorRepositoryMessage implements AsyncMessageInterface` mit `__construct(string $backupRepositoryId, ?string $checkId)`, Gettern; erbt **nicht** von `BackupRepositorySettings`. Handler `__invoke(MirrorRepositoryMessage $m): void` → `mirror($id, 'backup', $checkId)`, wirft nie.

- [ ] **Step 1: Failing Tests**

```php
// BackupTest
public function testDispatchesMirrorMessageAfterCheckWhenConfigured(): void
{ /* Bus-Mock: dispatch einmal mit MirrorRepositoryMessage(id, checkId); Reihenfolge: nach saveNewCheck, vor saveSnapshots */ }
public function testNoMirrorMessageWithoutConnection(): void {}
public function testMessageCarriesNoSecrets(): void
{ self::assertSame(['backupRepositoryId','checkId'], array_keys(get_object_vars($message))); }
// Handler
public function testHandlerNeverThrows(): void { /* mirror() wirft -> Handler loggt, kein Throw */ }
public function testHandlerPassesBackupTriggerAndCheckId(): void {}
```

- [ ] **Step 2–4:** FAIL → implementieren → PASS; danach komplette Unit-Suite gruen (Konstruktor-Aenderung). **Step 5:** Stand pruefen.

---

### Task 9: CLI `muckiware:repository:upload`

**Files:**
- Create: `src/Commands/RepositoryUpload.php`
- Modify: `services.xml` (`console.command`)
- Test: `tests/Commands/RepositoryUploadTest.php` (in `phpunit.unit.xml`)

**Interfaces:**
- Consumes: Task 6.
- Produces: `muckiware:repository:upload <backupRepositoryId> [--force]`; Basisklasse `Commands\Commands` (UUID-Pruefung); nimmt nicht-blockierend Lock `mucki-facility.backup` (belegt → Meldung, Exit 1); ruft `mirror($id, 'cli', null, $force)`; Ausgabe Status + Zaehler; Exit 0 nur bei `succeeded`; `null` → Meldung "keine Spiegelung konfiguriert oder laeuft bereits", Exit 1.

- [ ] **Step 1: Failing Tests** (`CommandTester`)

```php
public function testExitZeroOnlyOnSucceeded(): void {}
public function testForcePassesIgnoreCheck(): void { /* mirror(..., 'cli', null, true) */ }
public function testBusyBackupLockExitsOne(): void {}
```

- [ ] **Step 2–4:** FAIL → implementieren → PASS. **Step 5:** Stand pruefen.

---

### Task 10: Administration – S3-Verbindungen

**Files:**
- Create: `page/muwa-s3-connection-list/{index.js,muwa-s3-connection-list.html.twig}`, `page/muwa-s3-connection-detail/{index.js,muwa-s3-connection-detail.html.twig}`, `component/muwa-s3-connection-select/{index.js,muwa-s3-connection-select.html.twig}` (alle unter `src/Resources/app/administration/src/module/muwa-backup-repository/`)
- Modify: `index.js` (Registrierung, Routen `s3-connection/create`, `s3-connection/detail/:id`), `page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig` (Tab `s3ConnectionList` + Create-Button), `acl/index.js` (`muwa_s3_connection:read` viewer, `:update` editor, `:create` creator, `:delete` deleter), Snippets de-DE/en-GB (`s3Connection.*`)
- Test: `tests/Administration/S3ConnectionAdministrationTest.php` (Stil `DatabaseConnectionAdministrationTest`)

**Interfaces:**
- Consumes: Task 3 Routes.
- Produces: Komponente `muwa-s3-connection-select` (`v-model:value`, erster Eintrag "Keine Spiegelung" mit Wert `null`) fuer Task 11.

Verhalten 1:1 wie Datenbankverbindungen: Liste (Spalten `name`, `provider`, `endpoint`, `bucket`-frei), Loesch-Guard per `Criteria.equals('mirrorS3ConnectionId', item.id)` vor `.delete(item.id` mit Snippet `s3Connection.deleteInUse`; Detail mit Provider-Select (6.6/6.7-Zweige laut CLAUDE.md, `:options` mit `id/name` und `value/label`), Secret-Feld nie vorbelegt, Secret-Quelle, Region-Pflicht bei `aws`, Endpoint-Pflicht sonst, Button "Verbindung pruefen" mit optionalem Bucket-Feld.

- [ ] **Step 1: Failing Admin-Test** (Registrierung, Routen, Tab-Key, Spalten, Delete-Guard vor Delete, ACL-Privilegien, Snippet-Keys in beiden Sprachen, kein `$tc(` mit 3 Argumenten).
- [ ] **Step 2–4:** FAIL → implementieren → PASS; `ddev exec bin/build-administration.sh` (bzw. Projekt-Build) laeuft ohne Fehler.
- [ ] **Step 5:** Im Browser (6.7-Shop) anlegen, pruefen, loeschen-mit-Nutzung durchspielen. Stand pruefen.

---

### Task 11: Administration – Spiegel-Ziel, Historie, Dashboard

**Files:**
- Modify: `page/muwa-backup-repository-detail/{index.js,…html.twig}` (Abschnitt "S3-Spiegelung" im Tab Configuration mit eigenem Speichern ueber `PUT …/mirror`; Abschnitt Historie im Tab `backupRepositoryStats` mit Association `uploads` sortiert `createdAt DESC`, Limit 10, "zuletzt erfolgreich gespiegelt am"; Hinweis solange kein `succeeded`), `page/muwa-backup-repository-create/…` (nach erfolgreichem Init `PUT …/mirror`, falls gesetzt), `component/muwa-backup-status-dashboard-widget/{index.js,…html.twig}` (Association `uploads` `setLimit(1)`, Spalte `mirrorStatus` nur wenn ein Repository `mirrorS3ConnectionId` hat; Farben: `succeeded` success, `failed` danger, `skipped`/leer info; Tooltip Datum letzter Erfolg ueber zweite Association oder Feld — Builder-Wahl), Snippets
- Test: `tests/Administration/RepositoryMirrorAdministrationTest.php`

- [ ] **Step 1: Failing Admin-Test** (Mirror-Abschnitt nutzt `muwa-s3-connection-select`, Speichern ruft `/_action/muwa/backup/` + `/mirror` mit `PUT`, generisches `repository.save()` sendet keine `mirrorS3*`-Felder, Widget-Spalte `mirrorStatus`, Snippets beidsprachig).
- [ ] **Step 2–4:** FAIL → implementieren → PASS; Admin-Build ohne Fehler.
- [ ] **Step 5:** Browser: Ziel setzen, Ueberlappung provozieren (Fehlermeldung mit Namen), Backup starten → Historie und Dashboard zeigen Eintrag. Stand pruefen.

---

### Task 12: Doku, Gesamtpruefung, Ende-zu-Ende gegen MinIO

**Files:**
- Modify: `CHANGELOG.md`, `README.md`, `CLAUDE.md` (Abschnitte Entities, API-Routes, CLI, MessageQueue, Arbeitsregeln: S3-Spiegelung, `MUWA_S3_`), `composer.json` (Version bump, Minor)

- [ ] **Step 1:** Komplette Suiten: `phpunit -c phpunit.unit.xml` und `phpunit -c phpunit.xml` → gruen; `composer run-script phpstan` → keine neuen Fehler.
- [ ] **Step 2:** MinIO temporaer starten (`ddev exec` nicht noetig; z.B. `docker run --rm -p 9000:9000 minio/minio server /data` im ddev-Netz), Verbindung `minio` anlegen, Backup ausloesen, Worker laufen lassen (`bin/console messenger:consume async --limit=5`). Erwartet: Historie `succeeded`, Objekte im Bucket.
- [ ] **Step 3:** `restic -r s3:http://<minio>/<bucket>/<prefix> check` mit Repository-Passwort → `no errors were found`.
- [ ] **Step 4:** Snapshot in der Administration loeschen, naechstes Backup → remote geloeschte Packs, `restic check` gegen S3 weiter gruen.
- [ ] **Step 5:** Flow Builder oeffnen: beide Ausloeser sichtbar und benannt (6.7; wenn moeglich auch 6.6-Shop).
- [ ] **Step 6:** Stand pruefen, Diff dem Nutzer zur Sichtung uebergeben. Nicht committen.
