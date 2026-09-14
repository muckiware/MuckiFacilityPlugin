# Repository-Status-Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das Repository-Status-Panel wandert aus dem Snapshots-Tab in einen eigenen Tab zwischen Configuration und Checks; der Status wird nicht mehr beim Seitenaufruf erhoben, sondern am Ende jedes Backup-Laufs in eine neue Tabelle geschrieben.

**Architecture:** Neue DAL-Entity `muwa_backup_repository_stats` (1:n zum Repository, Historie). Ein neuer `Services\RepositoryStats` kapselt Erhebung und Persistenz und wird an drei Stellen aufgerufen: synchron am Ende von `Services\Backup::createBackup()`, aus einem neuen MessageQueue-Handler nach dem Löschen von Snapshots, und aus einem neuen CLI-Command. Die Administration liest die Entity direkt per DAL — kein HTTP-Call mehr beim Öffnen der Detail-Seite.

**Tech Stack:** Shopware 6.6/6.7 (DAL, MessageQueue, Symfony Console), PHP 8.2–8.4, PHPUnit 9.6, PHPStan Level 6, Vue-2-Style Admin-API.

**Spec:** `docs/superpowers/specs/2026-09-14-repository-status-tab-design.md`

## Global Constraints

- Plugin-Namespace ist `MuckiFacilityPlugin\`, **nicht** `LightsOn\`. Die LightsOn-PHP-Standards (`final`, `readonly`, `private`) gelten hier **nicht** — der Plugin-Stil ist `protected`, kein `final`, Constructor Promotion. Diesen Stil beibehalten.
- `declare(strict_types=1);` und der Muckiware-Dateikopf-Kommentar in **jeder** neuen PHP-Datei.
- **Kein Autowiring.** Jeder neue Service, Command, Handler und jede Entity-Definition muss in `src/Resources/config/services.xml` eingetragen werden.
- Repository-Passwörter nie loggen, nie in Messages serialisieren, nie `ApiAware` machen.
- Kompatibilität mit Shopware 6.6 **und** 6.7 gleichzeitig — es gibt keine getrennten Branches.
- **Nie `new \Doctrine\DBAL\Exception(...)`** — in DBAL 4 ist das ein Interface.
- `League\Flysystem\FilesystemException` erbt `Throwable`, **nicht** `\Exception` — ein `catch (\Exception $e)` fängt sie nicht. Immer eigener catch-Block, wie in `ManageRepository::generateStatsOutputs()`.
- Arbeit läuft auf Branch `feat/repository-status-tab`. **Nicht** nach `main` mergen, nicht pushen — das macht der Maintainer selbst.
- Alle Kommandos laufen über DDEV (Projekt `sw67`) vom Shop-Root `/Users/torstenfreyda/shopdev/sw6/sw67` aus.
- Version am Ende: `v0.7.0` → `v0.8.0`.

## Verifizierte Kommandos

Diese beiden Kommandos wurden in dieser Umgebung ausgeführt und funktionieren:

```bash
# PHPStan (Level 6) — muss nach jeder Task grün sein
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"

# Unit-Tests (nach Task 1)
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"
```

Der reguläre `phpunit.xml` mit `tests/TestBootstrap.php` läuft in diesem Projekt **nicht** — der Shopware-Test-Bootstrap bricht mit `Unknown column 'language.translation_auto_update'` ab. Deshalb Task 1.

## File Structure

**Neu:**

| Datei | Verantwortung |
|---|---|
| `tests/UnitTestBootstrap.php` | Autoloader für Unit-Tests ohne Shopware-Kernel |
| `phpunit.unit.xml` | Test-Suite, die ohne Test-DB läuft |
| `src/Migration/Migration1789430400.php` | Tabelle `muwa_backup_repository_stats` anlegen |
| `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsDefinition.php` | DAL-Felddefinition |
| `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsEntity.php` | Entity-Struct |
| `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsCollection.php` | Collection |
| `src/Services/Content/BackupRepositoryStats.php` | DAL-Wrapper: schreiben und neuesten Stand lesen |
| `src/Services/RepositoryStats.php` | Erhebung (restic + Verzeichnisgröße + Check-Status) |
| `src/MessageQueue/Message/UpdateRepositoryStatsMessage.php` | Async-Message, trägt nur die Repository-ID |
| `src/MessageQueue/Handler/UpdateRepositoryStatsHandler.php` | Ruft `collectAndSave()` |
| `src/Commands/RepositoryStats.php` | CLI `muckiware:repository:stats` |
| `tests/Services/RepositoryStatsTest.php` | Unit-Tests der Erhebung |
| `CHANGELOG.md` | Existiert bisher nicht |

**Geändert:**

| Datei | Änderung |
|---|---|
| `src/Core/Content/BackupRepository/BackupRepositoryDefinition.php:70-79` | Dritte `OneToManyAssociationField` |
| `src/Resources/config/services.xml` | 6 neue Einträge |
| `src/Services/Backup.php:37-70,131-149` | 9. Konstruktor-Parameter, Aufruf am Ende von `createBackup()` |
| `src/Controller/ManageController.php:29-36,57-69` | `MessageBusInterface`, Dispatch nach `removeSnapshots()` |
| `tests/Services/BackupTest.php:40,85,143` | 9. Mock in drei `new BackupService(...)`, zwei neue Tests |
| `.../muwa-backup-repository-detail.html.twig` | Tab, Panel, Verlaufsliste, Sidebar-`v-if`, alter Stats-Block raus |
| `.../muwa-backup-repository-detail/index.js` | DAL-Zugriff statt HTTP, alte Stats-Methode raus |
| `.../snippet/de-DE.json`, `.../snippet/en-GB.json` | Neue Keys |
| `README.md`, `CLAUDE.md`, `composer.json` | Doku und Version |

---

### Task 1: Unit-Test-Harness ohne Shopware-Kernel

Ohne dieses Harness kann keine der folgenden Tasks ihre Tests ausführen — der reguläre Bootstrap ist in diesem Projekt kaputt.

**Files:**
- Create: `tests/UnitTestBootstrap.php`
- Create: `phpunit.unit.xml`

**Interfaces:**
- Consumes: nichts
- Produces: Kommando `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`; alle folgenden Tasks tragen ihre Testdatei als `<file>` in `phpunit.unit.xml` nach.

- [ ] **Step 1: Bootstrap anlegen**

`tests/UnitTestBootstrap.php`. Der Autoloader des Shops liegt vier Ebenen über `tests/` (`tests` → `MuckiFacilityPlugin` → `static-plugins` → `custom` → Shop-Root). Der zweite Aufruf ist nötig, weil `autoload-dev` in `composer.json` `MuckiFacilityPlugin\` auf `tests/` mappt, die Testklassen aber im Namespace `MuckiFacilityPlugin\tests\` liegen — ohne diese Zeile findet PHPUnit sie nicht.

```php
<?php declare(strict_types=1);

$loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
$loader->addPsr4('MuckiFacilityPlugin\\tests\\', __DIR__);

return $loader;
```

- [ ] **Step 2: Test-Suite anlegen**

`phpunit.unit.xml`. Testdateien werden einzeln gelistet, nicht per `<directory>` — `tests/Integration/` und alles, was `tests/TestCaseBase/` nutzt, braucht einen Kernel und würde die Suite brechen.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.3/phpunit.xsd"
         bootstrap="tests/UnitTestBootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="MuckiFacility Unit Tests">
            <file>tests/Services/HelperTest.php</file>
            <file>tests/Services/BackupTest.php</file>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Suite laufen lassen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`
Expected: `OK (8 tests, 24 assertions)`

- [ ] **Step 4: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add tests/UnitTestBootstrap.php phpunit.unit.xml
git commit -m "test: add unit test suite that runs without the shopware kernel"
```

---

### Task 2: Entity, Migration und DAL-Registrierung

**Files:**
- Create: `src/Migration/Migration1789430400.php`
- Create: `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsDefinition.php`
- Create: `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsEntity.php`
- Create: `src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsCollection.php`
- Modify: `src/Core/Content/BackupRepository/BackupRepositoryDefinition.php:70-79`
- Modify: `src/Resources/config/services.xml:36-39`

**Interfaces:**
- Consumes: nichts
- Produces: Entity `muwa_backup_repository_stats` mit den DAL-Properties `id`, `backupRepositoryId`, `totalSize` (`?int`), `totalFileCount` (`?int`), `snapshotsCount` (`?int`), `fileSystemSize` (`?int`), `checkStatus` (`?string`), `createdAt`, `updatedAt`. Klasse `MuckiFacilityPlugin\Core\Content\BackupRepository\Stats\BackupRepositoryStatsEntity` mit Gettern/Settern für alle Properties. DI-Service-ID des DAL-Repositories: `muwa_backup_repository_stats.repository`.

- [ ] **Step 1: Migration schreiben**

`src/Migration/Migration1789430400.php`. Fremdschlüssel und Index-Namen weichen von `Migration1735644494` ab, weil Constraint-Namen pro Datenbank eindeutig sein müssen.

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1789430400 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789430400;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $query = '
            CREATE TABLE IF NOT EXISTS `muwa_backup_repository_stats` (
              `id` binary(16) NOT NULL,
              `backup_repository_id` binary(16) NOT NULL,
              `total_size` bigint DEFAULT NULL,
              `total_file_count` bigint DEFAULT NULL,
              `snapshots_count` int DEFAULT NULL,
              `file_system_size` bigint DEFAULT NULL,
              `check_status` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `created_at` datetime(3) NOT NULL,
              `updated_at` datetime(3) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `fk.backup_repository_stats.id_idx` (`backup_repository_id`),
              CONSTRAINT `fk.backup_repository_stats.id` FOREIGN KEY (`backup_repository_id`) REFERENCES `muwa_backup_repository` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ';
        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
```

- [ ] **Step 2: Entity schreiben**

`src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsEntity.php`. Alle Messwerte sind nullable — `restic stats` liefert je nach Repository-Zustand nicht alle Felder.

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Stats;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BackupRepositoryStatsEntity extends Entity
{
    use EntityIdTrait;

    protected string $backupRepositoryId;
    protected ?int $totalSize = null;
    protected ?int $totalFileCount = null;
    protected ?int $snapshotsCount = null;
    protected ?int $fileSystemSize = null;
    protected ?string $checkStatus = null;

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }

    public function setBackupRepositoryId(string $backupRepositoryId): void
    {
        $this->backupRepositoryId = $backupRepositoryId;
    }

    public function getTotalSize(): ?int
    {
        return $this->totalSize;
    }

    public function setTotalSize(?int $totalSize): void
    {
        $this->totalSize = $totalSize;
    }

    public function getTotalFileCount(): ?int
    {
        return $this->totalFileCount;
    }

    public function setTotalFileCount(?int $totalFileCount): void
    {
        $this->totalFileCount = $totalFileCount;
    }

    public function getSnapshotsCount(): ?int
    {
        return $this->snapshotsCount;
    }

    public function setSnapshotsCount(?int $snapshotsCount): void
    {
        $this->snapshotsCount = $snapshotsCount;
    }

    public function getFileSystemSize(): ?int
    {
        return $this->fileSystemSize;
    }

    public function setFileSystemSize(?int $fileSystemSize): void
    {
        $this->fileSystemSize = $fileSystemSize;
    }

    public function getCheckStatus(): ?string
    {
        return $this->checkStatus;
    }

    public function setCheckStatus(?string $checkStatus): void
    {
        $this->checkStatus = $checkStatus;
    }
}
```

- [ ] **Step 3: Collection schreiben**

`src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsCollection.php`:

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Stats;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class BackupRepositoryStatsCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BackupRepositoryStatsEntity::class;
    }
}
```

- [ ] **Step 4: Definition schreiben**

`src/Core/Content/BackupRepository/Stats/BackupRepositoryStatsDefinition.php`. Der FK trägt bewusst **kein** `ApiAware`, alle Messwerte schon — die Administration liest sie per DAL.

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Core\Content\BackupRepository\Stats;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryDefinition;

class BackupRepositoryStatsDefinition extends EntityDefinition
{
    const ENTITY_NAME = 'muwa_backup_repository_stats';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return BackupRepositoryStatsEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BackupRepositoryStatsCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField(
                'backup_repository_id',
                'backupRepositoryId',
                BackupRepositoryDefinition::class
            ))->addFlags(new Required()),
            (new IntField('total_size', 'totalSize'))->addFlags(new ApiAware()),
            (new IntField('total_file_count', 'totalFileCount'))->addFlags(new ApiAware()),
            (new IntField('snapshots_count', 'snapshotsCount'))->addFlags(new ApiAware()),
            (new IntField('file_system_size', 'fileSystemSize'))->addFlags(new ApiAware()),
            (new StringField('check_status', 'checkStatus'))->addFlags(new ApiAware()),

            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
```

- [ ] **Step 5: Assoziation im Repository ergänzen**

In `src/Core/Content/BackupRepository/BackupRepositoryDefinition.php` den Import ergänzen:

```php
use MuckiFacilityPlugin\Core\Content\BackupRepository\Stats\BackupRepositoryStatsDefinition;
```

und nach der `backupRepositorySnapshots`-Assoziation (Zeile 75-79) einfügen:

```php
            (new OneToManyAssociationField(
                'backupRepositoryStats',
                BackupRepositoryStatsDefinition::class,
                'backup_repository_id'
            )),
```

- [ ] **Step 6: Definition in services.xml registrieren**

In `src/Resources/config/services.xml` direkt nach dem `BackupRepositorySnapshotsDefinition`-Eintrag (Zeile 37-39):

```xml
        <service id="MuckiFacilityPlugin\Core\Content\BackupRepository\Stats\BackupRepositoryStatsDefinition">
            <tag name="shopware.entity.definition" entity="muwa_backup_repository_stats" />
        </service>
```

- [ ] **Step 7: PHPStan laufen lassen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"`
Expected: `[OK] No errors`

- [ ] **Step 8: Migration ausführen und Tabelle prüfen**

Run:
```bash
ddev exec "bin/console database:migrate --all MuckiFacilityPlugin"
ddev mysql -e "DESCRIBE muwa_backup_repository_stats;"
```
Expected: Neun Spalten wie in Step 1 definiert.

- [ ] **Step 9: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/Migration/Migration1789430400.php src/Core/Content/BackupRepository/Stats src/Core/Content/BackupRepository/BackupRepositoryDefinition.php src/Resources/config/services.xml
git commit -m "feat: add muwa_backup_repository_stats entity and migration"
```

---

### Task 3: Persistenz-Service `Services\Content\BackupRepositoryStats`

**Files:**
- Create: `src/Services/Content/BackupRepositoryStats.php`
- Modify: `src/Resources/config/services.xml:145-149`

**Interfaces:**
- Consumes: `BackupRepositoryStatsEntity` aus Task 2
- Produces: `MuckiFacilityPlugin\Services\Content\BackupRepositoryStats` mit
  `saveNewStats(string $backupRepositoryId, array $values): void` (erwartet die Keys `totalSize`, `totalFileCount`, `snapshotsCount`, `fileSystemSize`, `checkStatus`) und
  `getLatestStatsByRepositoryId(string $backupRepositoryId): ?BackupRepositoryStatsEntity`.
  Konstruktor-Reihenfolge: `LoggerInterface`, `SettingsInterface`, `EntityRepository`.

- [ ] **Step 1: Service schreiben**

`src/Services/Content/BackupRepositoryStats.php`, gebaut nach `Services\Content\BackupRepositoryChecks`:

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services\Content;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Stats\BackupRepositoryStatsEntity;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;

class BackupRepositoryStats
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupRepositoryStats,
    )
    {}

    /**
     * @param array{totalSize: int|null, totalFileCount: int|null, snapshotsCount: int|null, fileSystemSize: int|null, checkStatus: string|null} $values
     */
    public function saveNewStats(string $backupRepositoryId, array $values): void
    {
        $data = [
            'id' => Uuid::randomHex(),
            'backupRepositoryId' => $backupRepositoryId,
            'totalSize' => $values['totalSize'],
            'totalFileCount' => $values['totalFileCount'],
            'snapshotsCount' => $values['snapshotsCount'],
            'fileSystemSize' => $values['fileSystemSize'],
            'checkStatus' => $values['checkStatus'],
            'created_at' => new \DateTime()
        ];

        $this->logger->debug('saveNewStats '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $this->backupRepositoryStats->create([$data], Context::createDefaultContext());
    }

    public function getLatestStatsByRepositoryId(string $backupRepositoryId): ?BackupRepositoryStatsEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('backupRepositoryId', $backupRepositoryId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $results = $this->backupRepositoryStats->search($criteria, Context::createDefaultContext());
        if ($results->count() >= 1) {

            /** @var BackupRepositoryStatsEntity $result */
            $result = $results->last();
            return $result;
        }

        return null;
    }
}
```

- [ ] **Step 2: In services.xml registrieren**

Direkt nach dem `Services\Content\BackupRepositoryChecks`-Eintrag (Zeile 145-149):

```xml
        <service id="MuckiFacilityPlugin\Services\Content\BackupRepositoryStats" public="true">
            <argument type="service" id="Psr\Log\LoggerInterface"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\Settings"/>
            <argument type="service" id="muwa_backup_repository_stats.repository"/>
        </service>
```

- [ ] **Step 3: PHPStan laufen lassen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"`
Expected: `[OK] No errors`

- [ ] **Step 4: Container-Auflösung prüfen**

Run: `ddev exec "bin/console debug:container MuckiFacilityPlugin\\\\Services\\\\Content\\\\BackupRepositoryStats"`
Expected: Der Service wird gefunden, keine `ServiceNotFoundException`.

- [ ] **Step 5: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/Services/Content/BackupRepositoryStats.php src/Resources/config/services.xml
git commit -m "feat: add persistence service for repository stats"
```

---

### Task 4: Erhebungs-Service `Services\RepositoryStats`

Das Kernstück. Wird nach TDD gebaut: erst die Tests, dann die Implementierung.

**Files:**
- Create: `src/Services/RepositoryStats.php`
- Create: `tests/Services/RepositoryStatsTest.php`
- Modify: `phpunit.unit.xml`
- Modify: `src/Resources/config/services.xml`

**Interfaces:**
- Consumes: `Services\Content\BackupRepositoryStats::saveNewStats()` aus Task 3; `ManageRepository::getRepositoryStatsById(string $id, bool $isJsonOutput = true): string`; `Services\Content\BackupRepository::getBackupRepositoryById(string $id)`; `Services\Helper::getDirectorySize(string $path, bool $recursive = true): int`; `Services\Content\BackupRepositoryChecks::getLatestChecksByRepositoryId(string $id): ?BackupRepositoryChecksEntity`
- Produces: `MuckiFacilityPlugin\Services\RepositoryStats` mit
  `collect(string $backupRepositoryId): array` (Keys `totalSize`, `totalFileCount`, `snapshotsCount`, `fileSystemSize`, `checkStatus`) und
  `collectAndSave(string $backupRepositoryId): void` (wirft nie).
  Konstruktor-Reihenfolge: `LoggerInterface`, `ManageRepository`, `Content\BackupRepository`, `Content\BackupRepositoryChecks`, `Content\BackupRepositoryStats`, `Helper`.

- [ ] **Step 1: Testdatei schreiben**

`tests/Services/RepositoryStatsTest.php`:

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Services;

use League\Flysystem\UnableToListContents;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryStats;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\RepositoryStats;

class RepositoryStatsTest extends TestCase
{
    private function createBackupRepositoryMock(string $repositoryPath = '/tmp/repo'): BackupRepository
    {
        $entity = new BackupRepositoryEntity();
        $entity->setRepositoryPath($repositoryPath);

        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn($entity);

        return $backupRepository;
    }

    public function testCollectMapsCompleteResticOutput(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(
            json_encode(['total_size' => 4096, 'total_file_count' => 12, 'snapshots_count' => 3])
        );

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willReturn(8192);

        $checksEntity = new BackupRepositoryChecksEntity();
        $checksEntity->setCheckStatus('no errors were found');
        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn($checksEntity);

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $this->createMock(BackupRepositoryStats::class),
            $pluginHelper
        );

        $collected = $repositoryStats->collect(Uuid::randomHex());

        static::assertSame(4096, $collected['totalSize'], 'total_size should be mapped to totalSize');
        static::assertSame(12, $collected['totalFileCount'], 'total_file_count should be mapped to totalFileCount');
        static::assertSame(3, $collected['snapshotsCount'], 'snapshots_count should be mapped to snapshotsCount');
        static::assertSame(8192, $collected['fileSystemSize'], 'directory size should be mapped to fileSystemSize');
        static::assertSame('no errors were found', $collected['checkStatus'], 'latest check status should be copied');
    }

    public function testCollectReturnsNullForMissingResticKeys(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(json_encode(['total_size' => 0]));

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willReturn(0);

        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn(null);

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $this->createMock(BackupRepositoryStats::class),
            $pluginHelper
        );

        $collected = $repositoryStats->collect(Uuid::randomHex());

        static::assertSame(0, $collected['totalSize'], 'a present zero value must stay zero, not become null');
        static::assertNull($collected['totalFileCount'], 'missing total_file_count should be null');
        static::assertNull($collected['snapshotsCount'], 'missing snapshots_count should be null');
        static::assertNull($collected['checkStatus'], 'missing check should be null');
        static::assertSame(0, $collected['fileSystemSize'], 'a directory size of zero must stay zero');
    }

    public function testCollectKeepsResticValuesWhenDirectorySizeFails(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(
            json_encode(['total_size' => 4096, 'total_file_count' => 12, 'snapshots_count' => 3])
        );

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willThrowException(
            UnableToListContents::atLocation('/tmp/repo', true, new \RuntimeException('scan failed'))
        );

        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn(null);

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $this->createMock(BackupRepositoryStats::class),
            $pluginHelper
        );

        $collected = $repositoryStats->collect(Uuid::randomHex());

        static::assertNull($collected['fileSystemSize'], 'a failing directory scan should leave fileSystemSize null');
        static::assertSame(4096, $collected['totalSize'], 'restic values must survive a failing directory scan');
        static::assertSame(12, $collected['totalFileCount'], 'restic values must survive a failing directory scan');
    }

    public function testCollectAndSaveSwallowsExceptions(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willThrowException(
            new \Exception('Repository path does not exist: /tmp/repo')
        );

        $backupRepositoryStats = $this->createMock(BackupRepositoryStats::class);
        $backupRepositoryStats->expects(static::never())->method('saveNewStats');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::atLeastOnce())->method('error');

        $repositoryStats = new RepositoryStats(
            $logger,
            $manageService,
            $this->createBackupRepositoryMock(),
            $this->createMock(BackupRepositoryChecks::class),
            $backupRepositoryStats,
            $this->createMock(PluginHelper::class)
        );

        $repositoryStats->collectAndSave(Uuid::randomHex());

        static::assertTrue(true, 'collectAndSave must not let an exception escape');
    }
}
```

- [ ] **Step 2: Testdatei in die Suite aufnehmen**

In `phpunit.unit.xml` innerhalb von `<testsuite>` ergänzen:

```xml
            <file>tests/Services/RepositoryStatsTest.php</file>
```

- [ ] **Step 3: Tests laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`
Expected: FAIL mit `Class "MuckiFacilityPlugin\Services\RepositoryStats" does not exist`

- [ ] **Step 4: Service implementieren**

`src/Services/RepositoryStats.php`. Zwei getrennte catch-Blöcke, weil `FilesystemException` von `Throwable` erbt und nicht von `\Exception` — ein einzelner `catch (\Exception $e)` würde sie durchlassen.

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryStats;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;

class RepositoryStats
{
    public function __construct(
        protected LoggerInterface $logger,
        protected ManageService $manageService,
        protected BackupRepository $backupRepository,
        protected BackupRepositoryChecks $backupRepositoryChecks,
        protected BackupRepositoryStats $backupRepositoryStats,
        protected PluginHelper $pluginHelper,
    )
    {}

    /**
     * Collects the current state of a backup repository. Every measured value is nullable:
     * `restic stats` does not always return all keys, and the directory scan can fail on its own.
     *
     * @return array{totalSize: int|null, totalFileCount: int|null, snapshotsCount: int|null, fileSystemSize: int|null, checkStatus: string|null}
     */
    public function collect(string $backupRepositoryId): array
    {
        $collected = [
            'totalSize' => null,
            'totalFileCount' => null,
            'snapshotsCount' => null,
            'fileSystemSize' => null,
            'checkStatus' => null,
        ];

        $resticStats = json_decode($this->manageService->getRepositoryStatsById($backupRepositoryId), true);
        if (is_array($resticStats)) {

            if (array_key_exists('total_size', $resticStats)) {
                $collected['totalSize'] = (int) $resticStats['total_size'];
            }

            if (array_key_exists('total_file_count', $resticStats)) {
                $collected['totalFileCount'] = (int) $resticStats['total_file_count'];
            }

            if (array_key_exists('snapshots_count', $resticStats)) {
                $collected['snapshotsCount'] = (int) $resticStats['snapshots_count'];
            }
        }

        $collected['fileSystemSize'] = $this->collectFileSystemSize($backupRepositoryId);

        $checks = $this->backupRepositoryChecks->getLatestChecksByRepositoryId($backupRepositoryId);
        if ($checks !== null) {
            $collected['checkStatus'] = substr($checks->getCheckStatus(), 0, 254);
        }

        return $collected;
    }

    /**
     * Collects and persists the current state. Never throws: a failed status must not turn a
     * successful backup run into a failed one.
     */
    public function collectAndSave(string $backupRepositoryId): void
    {
        try {
            $this->backupRepositoryStats->saveNewStats($backupRepositoryId, $this->collect($backupRepositoryId));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }

    protected function collectFileSystemSize(string $backupRepositoryId): ?int
    {
        $backupRepository = $this->backupRepository->getBackupRepositoryById($backupRepositoryId);
        if ($backupRepository === null) {
            return null;
        }

        try {
            return $this->pluginHelper->getDirectorySize($backupRepository->getRepositoryPath());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }
}
```

- [ ] **Step 5: Tests laufen lassen, Erfolg bestätigen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`
Expected: `OK (12 tests, ...)` — die 8 bestehenden plus 4 neue.

- [ ] **Step 6: In services.xml registrieren**

Direkt vor dem `Services\Settings`-Eintrag (nach `Services\Backup`, Zeile 159):

```xml
        <service id="MuckiFacilityPlugin\Services\RepositoryStats" public="true">
            <argument type="service" id="Psr\Log\LoggerInterface"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\ManageRepository"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\Content\BackupRepository"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\Content\BackupRepositoryStats"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\Helper"/>
        </service>
```

- [ ] **Step 7: PHPStan und Container prüfen**

Run:
```bash
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"
ddev exec "bin/console debug:container MuckiFacilityPlugin\\\\Services\\\\RepositoryStats"
```
Expected: `[OK] No errors` und ein aufgelöster Service ohne `ServiceNotFoundException`.

- [ ] **Step 8: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/Services/RepositoryStats.php tests/Services/RepositoryStatsTest.php phpunit.unit.xml src/Resources/config/services.xml
git commit -m "feat: add repository stats collection service"
```

---

### Task 5: Trigger 1 — Status am Ende jedes Backup-Laufs

**Files:**
- Modify: `src/Services/Backup.php:37-70` (Konstruktor), `src/Services/Backup.php:131-149` (`createBackup()`)
- Modify: `tests/Services/BackupTest.php:40,85,143`
- Modify: `src/Resources/config/services.xml:150-159`

**Interfaces:**
- Consumes: `Services\RepositoryStats::collectAndSave(string $backupRepositoryId): void` aus Task 4
- Produces: `Services\Backup` mit 9 Konstruktor-Parametern — der neunte ist `protected RepositoryStats $repositoryStats`. Jeder `new BackupService(...)` braucht ab jetzt 9 Argumente.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

An `tests/Services/BackupTest.php` anhängen. Der Import gehört zu den übrigen `use`-Zeilen am Dateikopf:

```php
use MuckiFacilityPlugin\Services\RepositoryStats;
```

Dann als neue Methoden in der Klasse:

```php
    public function testCreateBackupCollectsRepositoryStats(): void
    {
        $backupRepositoryId = Uuid::randomHex();

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->expects(static::once())
            ->method('collectAndSave')
            ->with($backupRepositoryId);

        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRunnerFactory::class),
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $repositoryStats
        );

        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupRepositoryId($backupRepositoryId);
        $createBackup->setBackupType(BackupTypes::NONE_DATABASE->value);
        $createBackup->setBackupPaths([]);

        $backupService->createBackup($createBackup, false);
    }

    public function testCreateBackupSurvivesFailingRepositoryStats(): void
    {
        $backupRepositoryId = Uuid::randomHex();

        $repositoryStats = $this->createMock(RepositoryStats::class);
        $repositoryStats->method('collectAndSave')
            ->willThrowException(new \Exception('restic stats failed'));

        $backupService = new BackupService(
            $this->createMock(LoggerInterface::class),
            $this->createMock(BackupRunnerFactory::class),
            $this->createMock(BackupRepository::class),
            $this->createMock(BackupRepositoryChecks::class),
            $this->createMock(PluginSettings::class),
            $this->createMock(PluginHelper::class),
            $this->createMock(ManageService::class),
            $this->createMock(ServicesCliOutput::class),
            $repositoryStats
        );

        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupRepositoryId($backupRepositoryId);
        $createBackup->setBackupType(BackupTypes::NONE_DATABASE->value);
        $createBackup->setBackupPaths([]);

        $this->expectNotToPerformAssertions();
        $backupService->createBackup($createBackup, false);
    }
```

Hinweis zum zweiten Test: Er dokumentiert, dass `createBackup()` selbst **keinen** try/catch braucht — die Garantie liegt in `RepositoryStats::collectAndSave()`. Schlägt er fehl, weil die Exception durchkommt, ist das der erwartete Zustand für einen Mock, der die Garantie umgeht. Falls der Test in dieser Form rot bleibt, ist die richtige Reaktion, ihn zu entfernen statt einen zweiten try/catch in `createBackup()` einzubauen — die Garantie gehört an genau eine Stelle. Test 1 ist der verbindliche.

- [ ] **Step 2: Bestehende Instanzierungen anpassen**

In `tests/Services/BackupTest.php` bei allen drei bestehenden `new BackupService(...)` (Zeilen 40, 85, 143) als neuntes Argument ergänzen:

```php
            $this->createMock(RepositoryStats::class)
```

Das achte Argument braucht dafür ein Komma am Zeilenende.

- [ ] **Step 3: Tests laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"`
Expected: FAIL mit `ArgumentCountError` bzw. `Too few arguments to function ...Backup::__construct()`

- [ ] **Step 4: Konstruktor erweitern**

In `src/Services/Backup.php` den Import ergänzen:

```php
use MuckiFacilityPlugin\Services\RepositoryStats;
```

Im Konstruktor als letzten Parameter anhängen (das bisherige `protected ServicesCliOutput $servicesCliOutput` braucht ein Komma):

```php
        protected ServicesCliOutput $servicesCliOutput,
        protected RepositoryStats $repositoryStats
```

Und den `@param`-Block darüber um eine Zeile ergänzen:

```php
     * @param RepositoryStats $repositoryStats
```

- [ ] **Step 5: Aufruf in createBackup() ergänzen**

In `src/Services/Backup.php` am Ende von `createBackup()` (nach Zeile 148):

```php
        $this->createCheckItem($createBackup);
        $this->manageService->saveSnapshots($createBackup->getBackupRepositoryId());
        $this->repositoryStats->collectAndSave($createBackup->getBackupRepositoryId());
```

- [ ] **Step 6: services.xml anpassen**

Im `MuckiFacilityPlugin\Services\Backup`-Eintrag als letztes Argument nach `CliOutput`:

```xml
            <argument type="service" id="MuckiFacilityPlugin\Services\RepositoryStats"/>
```

- [ ] **Step 7: Tests und PHPStan laufen lassen**

Run:
```bash
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"
```
Expected: alle Tests grün, `[OK] No errors`

- [ ] **Step 8: Backup-Lauf manuell verifizieren**

Das ist der Kern-Trigger dieser Änderung — er muss einmal echt laufen, nicht nur gemockt.

Run:
```bash
ddev exec "bin/console cache:clear"
ddev mysql -e "SELECT LOWER(HEX(id)) AS id, internal_name FROM muwa_backup_repository LIMIT 5;"
ddev mysql -e "SELECT COUNT(*) FROM muwa_backup_repository_stats;"
ddev exec "bin/console muckiware:backup:create <id-aus-der-liste>"
ddev mysql -e "SELECT LOWER(HEX(backup_repository_id)) AS repo, total_size, total_file_count, snapshots_count, file_system_size, check_status, created_at FROM muwa_backup_repository_stats ORDER BY created_at DESC LIMIT 1;"
```

Expected: Der Zähler ist um genau 1 gestiegen, und die neueste Zeile trägt die ID des gesicherten
Repositories. `total_size`, `total_file_count` und `snapshots_count` sind gefüllt;
`file_system_size` ebenfalls, sofern der Repository-Pfad lesbar ist. `check_status` ist gefüllt,
weil `createCheckItem()` unmittelbar davor läuft.

Falls Werte `NULL` sind, ist das kein Fehlschlag dieses Schritts, sondern ein Hinweis: in
`var/log/` nach Einträgen des `muwa`-Kanals suchen — `RepositoryStats` loggt jede geschluckte
Exception.

- [ ] **Step 9: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/Services/Backup.php tests/Services/BackupTest.php src/Resources/config/services.xml
git commit -m "feat: collect repository stats at the end of every backup run"
```

---

### Task 6: Trigger 2 — Message, Handler und Controller-Dispatch

**Files:**
- Create: `src/MessageQueue/Message/UpdateRepositoryStatsMessage.php`
- Create: `src/MessageQueue/Handler/UpdateRepositoryStatsHandler.php`
- Modify: `src/Controller/ManageController.php:29-36,57-69`
- Modify: `src/Resources/config/services.xml:16-21,189-201`

**Interfaces:**
- Consumes: `Services\RepositoryStats::collectAndSave()` aus Task 4
- Produces: `MuckiFacilityPlugin\MessageQueue\Message\UpdateRepositoryStatsMessage` mit Konstruktor `__construct(string $backupRepositoryId)` und `getBackupRepositoryId(): string`. `ManageController` hat ab jetzt drei Konstruktor-Parameter: `LoggerInterface`, `ManageService`, `MessageBusInterface`.

- [ ] **Step 1: Message schreiben**

`src/MessageQueue/Message/UpdateRepositoryStatsMessage.php`. Die Message trägt bewusst **nur** die ID — kein Passwort, keine Vererbung von `BackupRepositorySettings`. Eine eigene Klasse mit eigener Typisierung ist nötig, damit sie nicht von den auf `CreateBackupMessage` typisierten Handlern mitgefangen wird.

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class UpdateRepositoryStatsMessage implements AsyncMessageInterface
{
    public function __construct(
        protected string $backupRepositoryId
    )
    {}

    public function getBackupRepositoryId(): string
    {
        return $this->backupRepositoryId;
    }
}
```

- [ ] **Step 2: Handler schreiben**

`src/MessageQueue/Handler/UpdateRepositoryStatsHandler.php`:

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\MessageQueue\Handler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\MessageQueue\Message\UpdateRepositoryStatsMessage;
use MuckiFacilityPlugin\Services\RepositoryStats;

#[AsMessageHandler]
class UpdateRepositoryStatsHandler
{
    public function __construct(
        protected LoggerInterface $logger,
        protected RepositoryStats $repositoryStats
    )
    {}

    public function __invoke(UpdateRepositoryStatsMessage $message): void
    {
        $this->logger->debug(
            'Repository stats update started. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );

        $this->repositoryStats->collectAndSave($message->getBackupRepositoryId());

        $this->logger->debug(
            'Repository stats update done. BackupRepositoryId: '.$message->getBackupRepositoryId(),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );
    }
}
```

- [ ] **Step 3: Controller erweitern**

In `src/Controller/ManageController.php` die Imports ergänzen:

```php
use Symfony\Component\Messenger\MessageBusInterface;

use MuckiFacilityPlugin\MessageQueue\Message\UpdateRepositoryStatsMessage;
```

Konstruktor um einen dritten Parameter erweitern:

```php
    public function __construct(
        protected LoggerInterface $logger,
        protected ManageService $manageService,
        protected MessageBusInterface $bus,
    )
    {}
```

`removeSnapshots()` um den Dispatch ergänzen — einmal pro Request, nicht je gelöschtem Snapshot, und nur wenn die ID gültig ist:

```php
    public function removeSnapshots(RequestDataBag $requestDataBag, Context $context): Response
    {
        $backupRepositoryId = $requestDataBag->get('backupRepositoryId');
        $removedSnapshot = $this->removeSnapshotsByIds(
            $this->getSnapshotIds($requestDataBag),
            $backupRepositoryId
        );

        if (is_string($backupRepositoryId) && Uuid::isValid($backupRepositoryId)) {
            $this->bus->dispatch(new UpdateRepositoryStatsMessage($backupRepositoryId));
        }

        return new JsonResponse($removedSnapshot);
    }
```

- [ ] **Step 4: services.xml anpassen**

Im `ManageController`-Eintrag (Zeile 16-21) als drittes Argument vor dem `<call>`-Block:

```xml
            <argument type="service" id="messenger.default_bus"/>
```

Nach dem `RestoreSnapshotHandler`-Eintrag (Zeile 201) den neuen Handler ergänzen. Die Doppelregistrierung aus Attribut **und** Tag entspricht dem Bestand:

```xml
        <service id="MuckiFacilityPlugin\MessageQueue\Handler\UpdateRepositoryStatsHandler">
            <argument type="service" id="Psr\Log\LoggerInterface"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\RepositoryStats"/>
            <tag name="messenger.message_handler"/>
        </service>
```

- [ ] **Step 5: PHPStan und Container prüfen**

Run:
```bash
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"
ddev exec "bin/console debug:messenger" 
```
Expected: `[OK] No errors`; in der Messenger-Übersicht taucht `UpdateRepositoryStatsMessage` mit genau **einem** Handler auf.

- [ ] **Step 6: Dispatch manuell prüfen**

Run:
```bash
ddev exec "bin/console cache:clear"
ddev exec "bin/console messenger:consume async --limit=1 -vv"
```
In einem zweiten Schritt im Admin einen Snapshot löschen und prüfen, dass der Consumer `UpdateRepositoryStatsHandler` ausführt und eine neue Zeile entsteht:
```bash
ddev mysql -e "SELECT COUNT(*) FROM muwa_backup_repository_stats;"
```
Expected: Zähler steigt um 1.

- [ ] **Step 7: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/MessageQueue src/Controller/ManageController.php src/Resources/config/services.xml
git commit -m "feat: refresh repository stats asynchronously after snapshot removal"
```

---

### Task 7: Trigger 3 — CLI-Command

**Files:**
- Create: `src/Commands/RepositoryStats.php`
- Modify: `src/Resources/config/services.xml:126-135`

**Interfaces:**
- Consumes: `Services\RepositoryStats::collect()` und `::collectAndSave()` aus Task 4
- Produces: CLI-Command `muckiware:repository:stats <backupRepositoryId>`

- [ ] **Step 1: Command schreiben**

`src/Commands/RepositoryStats.php`, nach dem Vorbild von `Commands\ManageSnapshots`. Der Command ruft `collectAndSave()` **direkt** statt zu dispatchen, damit ein Cron-Lauf ohne Worker verlässlich funktioniert. Der Klassenname kollidiert mit `Services\RepositoryStats`, deshalb der Alias-Import.

```php
<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Commands;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\RepositoryStats as RepositoryStatsService;

#[AsCommand(
    name: 'muckiware:repository:stats',
    description: 'Collect and persist the current status of a backup repository'
)]
class RepositoryStats extends Command
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected RepositoryStatsService $repositoryStats
    )
    {
        parent::__construct();
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /**
     * @internal
     */
    public function configure(): void
    {
        $this->setDescription('Id for the existing backup repository');
        $this->addArgument('backupRepositoryId', InputArgument::REQUIRED, 'Backup repository id');
        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $backupRepositoryId = $this->checkInputForBackupRepositoryId($input);
        if (!$this->pluginSettings->isEnabled()) {
            $output->writeln('MuckiFacilityPlugin is not enabled');
            return 0;
        }

        $this->repositoryStats->collectAndSave($backupRepositoryId);

        foreach ($this->repositoryStats->collect($backupRepositoryId) as $label => $value) {
            $output->writeln($label.': '.($value ?? '-'));
        }

        return 0;
    }

    protected function checkInputForBackupRepositoryId(InputInterface $input): string
    {
        $backupRepositoryId = $input->getArgument('backupRepositoryId');
        if ($backupRepositoryId !== '' && Uuid::isValid($backupRepositoryId)) {
            return $backupRepositoryId;
        }

        throw new \InvalidArgumentException('Invalid or missing backup repository id');
    }
}
```

- [ ] **Step 2: In services.xml registrieren**

Nach dem `DbTableCleanup`-Command-Eintrag (Zeile 126-135):

```xml
        <service class="MuckiFacilityPlugin\Commands\RepositoryStats" id="muwa.command.repository.stats" public="true">
            <argument type="service" id="Psr\Log\LoggerInterface"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\Settings"/>
            <argument type="service" id="MuckiFacilityPlugin\Services\RepositoryStats"/>
            <tag name="console.command"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>
```

- [ ] **Step 3: PHPStan laufen lassen**

Run: `ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"`
Expected: `[OK] No errors`

- [ ] **Step 4: Command manuell ausführen**

Run:
```bash
ddev exec "bin/console list muckiware"
ddev mysql -e "SELECT LOWER(HEX(id)) FROM muwa_backup_repository LIMIT 1;"
ddev exec "bin/console muckiware:repository:stats <id-aus-der-vorigen-zeile>"
```
Expected: `muckiware:repository:stats` steht in der Liste; der Lauf gibt fünf Zeilen aus (`totalSize`, `totalFileCount`, `snapshotsCount`, `fileSystemSize`, `checkStatus`), Werte oder `-`.

- [ ] **Step 5: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/Commands/RepositoryStats.php src/Resources/config/services.xml
git commit -m "feat: add muckiware:repository:stats command"
```

---

### Task 8: Administration — eigener Tab

**Files:**
- Modify: `src/Resources/app/administration/src/module/muwa-backup-repository/page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig:37-62,415-437,560`
- Modify: `src/Resources/app/administration/src/module/muwa-backup-repository/page/muwa-backup-repository-detail/index.js:62-83,108-176,273-279,308-314,527-541`
- Modify: `src/Resources/app/administration/src/module/muwa-backup-repository/snippet/de-DE.json`
- Modify: `src/Resources/app/administration/src/module/muwa-backup-repository/snippet/en-GB.json`

**Interfaces:**
- Consumes: Entity `muwa_backup_repository_stats` aus Task 2 mit den Properties `totalSize`, `totalFileCount`, `snapshotsCount`, `fileSystemSize`, `checkStatus`, `createdAt`
- Produces: Tab-Key `backupRepositoryStats`

- [ ] **Step 1: Snippets ergänzen**

In `snippet/de-DE.json` unter `muwa-backup-repository.tabs` ergänzen:

```json
        "stats": "Repository Status"
```

Unter `muwa-backup-repository.detail` ergänzen:

```json
        "statsCreatedAtLabel": "Stand",
        "statsHistoryLabel": "Verlauf",
        "statsEmptyTitle": "Noch kein Status erhoben",
        "statsEmptySubline": "Der Status wird am Ende jedes Backup-Laufs erhoben. Alternativ per CLI: bin/console muckiware:repository:stats <id>",
        "statsNoValue": "–"
```

In `snippet/en-GB.json` an denselben Stellen:

```json
        "stats": "Repository status"
```

```json
        "statsCreatedAtLabel": "As of",
        "statsHistoryLabel": "History",
        "statsEmptyTitle": "No status collected yet",
        "statsEmptySubline": "The status is collected at the end of every backup run. Alternatively via CLI: bin/console muckiware:repository:stats <id>",
        "statsNoValue": "–"
```

Die vorhandenen Labels `totalFileSystemSizeLabel`, `totalFileRepositorySizeLabel`, `totalSnapshotsLabel`, `totalFilesLabel` und `CheckStatusLabel` unter `muwa-backup-repository.list` bleiben unverändert und werden wiederverwendet.

- [ ] **Step 2: Tab in das Template einfügen**

In `muwa-backup-repository-detail.html.twig` zwischen dem `backupRepositoryConfig`- und dem `backupRepositoryChecks`-Eintrag (nach Zeile 46):

```twig
                            <sw-tabs-item
                                key="backupRepositoryStats"
                                :active-tab="active"
                                :route="{ name: 'muwa.backup.repository.detail', params: { id: backupRepository.id, tab: 'backupRepositoryStats' } }"
                            >
                                {{ $tc('muwa-backup-repository.tabs.stats') }}
                            </sw-tabs-item>
```

- [ ] **Step 3: Altes Stats-Panel aus dem Snapshots-Tab entfernen**

In `muwa-backup-repository-detail.html.twig` den kompletten Block von `<sw-card positionIdentifier="muwaBackupRepositoryStats"` bis zum zugehörigen `</sw-card>` (Zeilen 415-437, beginnend mit dem Kommentar `{# Tab Snapshots #}`) löschen. Der Kommentar `{# Tab Snapshots #}` bleibt und rückt vor die danach folgende `sw-entity-listing`.

- [ ] **Step 4: Neuen Tab-Inhalt einfügen**

An derselben Stelle, vor `{# Tab Snapshots #}`, einfügen:

```twig
                    {# Tab Repository Status #}
                    <sw-card
                        positionIdentifier="muwaBackupRepositoryStatsPanel"
                        v-if="backupRepository && tab === 'backupRepositoryStats'"
                        :title="$tc('muwa-backup-repository.list.stats')"
                        :isLoading="isStatsLoading"
                        :large="true"
                    >
                        <template v-if="latestStats">
                            <sw-description-list>
                                <dt>{{ $tc('muwa-backup-repository.detail.statsCreatedAtLabel') }}</dt>
                                <dd>{{ dateFilter(latestStats.createdAt, { hour: '2-digit', minute: '2-digit' }) }}</dd>
                                <dt>{{ $tc('muwa-backup-repository.list.totalFileSystemSizeLabel') }}</dt>
                                <dd>{{ formatBytes(latestStats.fileSystemSize) }}</dd>
                                <dt>{{ $tc('muwa-backup-repository.list.totalFileRepositorySizeLabel') }}</dt>
                                <dd>{{ formatBytes(latestStats.totalSize) }}</dd>
                                <dt>{{ $tc('muwa-backup-repository.list.totalSnapshotsLabel') }}</dt>
                                <dd>{{ formatCount(latestStats.snapshotsCount) }}</dd>
                                <dt>{{ $tc('muwa-backup-repository.list.totalFilesLabel') }}</dt>
                                <dd>{{ formatCount(latestStats.totalFileCount) }}</dd>
                                <dt>{{ $tc('muwa-backup-repository.list.CheckStatusLabel') }}</dt>
                                <dd>{{ latestStats.checkStatus || $tc('muwa-backup-repository.detail.statsNoValue') }}</dd>
                            </sw-description-list>
                        </template>
                        <sw-empty-state
                            v-else-if="!isStatsLoading"
                            :absolute="false"
                            :title="$tc('muwa-backup-repository.detail.statsEmptyTitle')"
                            :subline="$tc('muwa-backup-repository.detail.statsEmptySubline')"
                        />
                    </sw-card>
                    <sw-card
                        positionIdentifier="muwaBackupRepositoryStatsHistory"
                        v-if="backupRepository && tab === 'backupRepositoryStats' && backupRepositoryStats.length"
                        :title="$tc('muwa-backup-repository.detail.statsHistoryLabel')"
                        :large="true"
                    >
                        <template #grid>
                            <sw-data-grid
                                :showSelection="false"
                                :showActions="false"
                                :showSettings="false"
                                :dataSource="backupRepositoryStats"
                                :columns="statsHistoryColumns"
                                :plain-appearance="true"
                                :is-loading="isStatsLoading"
                            >
                                <template #column-createdAt="{ item }">
                                    {{ dateFilter(item.createdAt, { hour: '2-digit', minute: '2-digit' }) }}
                                </template>
                                <template #column-fileSystemSize="{ item }">
                                    {{ formatBytes(item.fileSystemSize) }}
                                </template>
                                <template #column-totalSize="{ item }">
                                    {{ formatBytes(item.totalSize) }}
                                </template>
                                <template #column-snapshotsCount="{ item }">
                                    {{ formatCount(item.snapshotsCount) }}
                                </template>
                                <template #column-totalFileCount="{ item }">
                                    {{ formatCount(item.totalFileCount) }}
                                </template>
                            </sw-data-grid>
                        </template>
                    </sw-card>
```

- [ ] **Step 5: Sidebar-Refresh für den neuen Tab freischalten**

In `muwa-backup-repository-detail.html.twig` Zeile 560 die Bedingung erweitern:

```twig
                <sw-sidebar class="muwa-backup-repository-list__sidebar" v-if="tab === 'backupRepositoryChecks' || tab === 'backupRepositorySnapshots' || tab === 'backupRepositoryStats'">
```

- [ ] **Step 6: index.js — Data-Properties austauschen**

In `data()` die Zeilen `requestRepositoryStats: '/_action/muwa/repository/stats',` und `stats: null` entfernen und stattdessen ergänzen:

```javascript
            backupRepositoryStats: [],
```

- [ ] **Step 7: index.js — Computeds ergänzen**

Nach `backupRepositorySnapshotsRepository()` (Zeile 118-121) einfügen:

```javascript
        backupRepositoryStatsRepository() {
            return this.repositoryFactory.create('muwa_backup_repository_stats');
        },

        latestStats() {

            if (this.backupRepositoryStats && this.backupRepositoryStats.length) {
                return this.backupRepositoryStats[0];
            }
            return null;
        },
```

Das Computed `statsColumns()` (Zeile 160-176) durch `statsHistoryColumns()` ersetzen:

```javascript
        statsHistoryColumns() {

            return [
                {
                    property: 'createdAt',
                    label: 'muwa-backup-repository.detail.statsCreatedAtLabel',
                    allowResize: true,
                    width: '20%',
                },
                {
                    property: 'fileSystemSize',
                    label: 'muwa-backup-repository.list.totalFileSystemSizeLabel',
                    allowResize: true,
                    width: '20%',
                },
                {
                    property: 'totalSize',
                    label: 'muwa-backup-repository.list.totalFileRepositorySizeLabel',
                    allowResize: true,
                    width: '20%',
                },
                {
                    property: 'snapshotsCount',
                    label: 'muwa-backup-repository.list.totalSnapshotsLabel',
                    allowResize: true,
                    align: 'right',
                    width: '20%',
                },
                {
                    property: 'totalFileCount',
                    label: 'muwa-backup-repository.list.totalFilesLabel',
                    allowResize: true,
                    align: 'right',
                    width: '20%',
                }
            ];
        },
```

- [ ] **Step 8: index.js — Methoden austauschen**

`getBackupRepositoryStats()` (Zeile 527-541) vollständig durch die folgenden drei Methoden ersetzen. `Shopware.Utils.format.fileSize()` statt des `fileSize`-Filters, weil der Filter bei `0` einen Leerstring liefert und „0 Bytes" von „nicht erhoben" unterscheidbar bleiben muss:

```javascript
        fetchBackupRepositoryStats() {

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.addFilter(Criteria.equals('backupRepositoryId', this.$route.params.id));
            criteria.setLimit(10);

            this.isStatsLoading = true;
            return this.backupRepositoryStatsRepository.search(criteria, Context.api).then((collection) => {

                this.backupRepositoryStats = collection;
                this.isStatsLoading = false;
                return this.backupRepositoryStats;
            });
        },

        formatBytes(value) {

            // Shopware.Utils.format.fileSize(bytes, locale = 'de-DE') — das locale-Argument wird
            // bewusst weggelassen: Shopware.State ist in 6.7 deprecated, Shopware.Store gibt es in
            // 6.6 nicht. Der Default deckt beide Majors ohne Versionszweig ab.
            if (value === null || value === undefined) {
                return this.$tc('muwa-backup-repository.detail.statsNoValue');
            }
            return Shopware.Utils.format.fileSize(value);
        },

        formatCount(value) {

            if (value === null || value === undefined) {
                return this.$tc('muwa-backup-repository.detail.statsNoValue');
            }
            return value.toLocaleString();
        },
```

- [ ] **Step 9: index.js — Aufrufer umbiegen**

In `createdComponent()` (Zeile 278) und in `onRefresh()` (Zeile 313) jeweils `this.getBackupRepositoryStats();` ersetzen durch:

```javascript
            this.fetchBackupRepositoryStats();
```

- [ ] **Step 10: Administration bauen**

Run:
```bash
ddev exec "bin/console cache:clear"
ddev exec "bin/build-administration.sh"
```
Expected: Build ohne Fehler.

- [ ] **Step 11: Im Browser prüfen**

Detail-Seite eines Repositories öffnen (`Einstellungen → Erweiterungen → Backup Repository → <ein Repository>`) und prüfen:
1. Vier Tabs in der Reihenfolge Configuration, Repository Status, Checks, Snapshots.
2. Die Seite lädt sofort — kein minutenlanges Warten mehr.
3. Der Status-Tab zeigt Panel und Verlauf (oder den Empty-State, falls noch kein Datensatz existiert).
4. Im Snapshots-Tab ist das alte Status-Panel verschwunden.
5. Das Sidebar-Refresh-Icon ist im Status-Tab sichtbar und lädt die Liste neu.
6. Die Browser-Konsole zeigt keine Vue-Fehler.

- [ ] **Step 12: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add src/Resources/app/administration
git commit -m "feat: move repository status into its own tab reading from the database"
```

---

### Task 9: Dokumentation und Version

**Files:**
- Create: `CHANGELOG.md`
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `composer.json:4`

**Interfaces:**
- Consumes: alles aus Task 2 bis 8
- Produces: nichts

- [ ] **Step 1: CHANGELOG.md anlegen**

```markdown
# Changelog

All notable changes to this project will be documented in this file.

## [0.8.0]

### Added
- New tab "Repository Status" on the backup repository detail page, placed between
  Configuration and Checks.
- New table `muwa_backup_repository_stats` keeping a history of repository statistics per
  backup run.
- New CLI command `muckiware:repository:stats <backupRepositoryId>` to collect and persist the
  status independently of a backup run.

### Changed
- Repository statistics are no longer collected when the detail page is opened. They are
  collected at the end of every backup run, after removing snapshots, and on demand via the new
  CLI command. Opening the detail page of a large repository is no longer slow.

### Notes
- The route `GET /api/_action/muwa/repository/stats/{id}` is unchanged and still performs a live
  `restic stats` call.
- Existing repositories get their first status record with the next backup run or CLI call.
```

- [ ] **Step 2: README.md ergänzen**

Den neuen Tab in der Beschreibung der Administration nennen und den Command
`muckiware:repository:stats <backupRepositoryId>` in die Liste der CLI-Commands aufnehmen —
an denselben Stellen, an denen `muckiware:backup:snapshots` beschrieben ist.

- [ ] **Step 3: CLAUDE.md aktualisieren**

Vier Stellen:

1. Entity-Tabelle: Zeile für `muwa_backup_repository_stats` — „Historie der Repository-Statistik je Backup-Lauf (Rohwerte in Bytes/Anzahl)", Relation „n:1 Repository".
2. CLI-Tabelle: Zeile `muckiware:repository:stats` | `Commands\RepositoryStats` | `backupRepositoryId`.
3. MessageQueue-Abschnitt: `UpdateRepositoryStatsMessage` / `UpdateRepositoryStatsHandler` ergänzen, mit dem Hinweis, dass die Message **nicht** von `BackupRepositorySettings` erbt und deshalb nicht von der doppelten `CreateBackupMessage`-Typisierung betroffen ist.
4. Architektur-Diagramm: `Services\RepositoryStats` als vierten Zweig unter Services aufnehmen.

Zusätzlich im Abschnitt „Tests & Statische Analyse" das neue Unit-Test-Kommando aufnehmen:

```bash
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"
```

mit dem Hinweis, dass `phpunit.xml` (Shopware-Bootstrap) in `sw67` derzeit nicht läuft.

- [ ] **Step 4: Version anheben**

In `composer.json` Zeile 4:

```json
	"version": "v0.8.0",
```

- [ ] **Step 5: Gesamtlauf zur Absicherung**

Run:
```bash
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpunit -c phpunit.unit.xml"
ddev exec "cd custom/static-plugins/MuckiFacilityPlugin && /var/www/html/vendor/bin/phpstan analyse -n --no-progress"
```
Expected: alle Tests grün, `[OK] No errors`

- [ ] **Step 6: Commit**

```bash
cd /Users/torstenfreyda/shopdev/sw6/sw67/custom/static-plugins/MuckiFacilityPlugin
git add CHANGELOG.md README.md CLAUDE.md composer.json
git commit -m "docs: document repository status tab and bump to v0.8.0"
```

- [ ] **Step 7: Abschluss melden**

Branch `feat/repository-status-tab` liegen lassen. **Nicht** nach `main` mergen, **nicht** pushen — das entscheidet der Maintainer. Dem Nutzer melden, was gelaufen ist und was manuell verifiziert wurde.

---

## Offene Punkte für den Ausführenden

- Der Vollständigkeits-Beweis der Änderung ist Schritt 11.2 in Task 8: Die Detail-Seite eines
  großen Repositories muss sofort laden. Ohne ein ausreichend großes Testrepository lässt sich
  das nur qualitativ prüfen.
- Die in der `CLAUDE.md` dokumentierten Upstream-Bugs bleiben unberührt. Insbesondere nicht
  „nebenbei" den `RestoreSnapshotController` reparieren — das ist ein eigener Vorgang.
