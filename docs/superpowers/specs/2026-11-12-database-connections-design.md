# Eigene Datenbankverbindungen fuer Datenbank-Backups

Design-Dokument, 12.11.2026. Plugin `muckiware/facility-plugin`.

## Warum

Das Datenbank-Backup sichert heute immer die Systemdatenbank (`DATABASE_URL`). Im
MuckiSafe-Kontext muss eine Instanz aber auch fremde Datenbanken sichern koennen — auf demselben
Datenbankserver in einer anderen Instanz/einem anderen Schema oder auf einem ganz anderen Host.
Im reinen Shopware-Einsatz ist das selten, fuer MuckiSafe ist es ein Kernfeature.

## Ablauf aus Nutzersicht

1. In der Administration (Backup-Modul) gibt es eine eigene Seite **Datenbankverbindungen**.
   Dort legt man Verbindungen an (Name, Typ, Host, Port, Datenbankname, User, Passwort) und
   prueft sie per Button **Verbindung pruefen**.
2. In der Backup-Konfiguration (Create-Seite und Detail-Tab Configuration) waehlt man die
   Verbindung per Dropdown. Erster Eintrag ist immer **Standardverbindung (System)**. Solange
   keine eigene Verbindung angelegt ist, steht nur dieser Eintrag im Dropdown.
3. Das Backup dumpt die gewaehlte Datenbank. Ist die gewaehlte Verbindung nicht nutzbar, bricht
   der Datenbankteil des Backups mit klarer Fehlermeldung ab.

## Ziele

- Neue Tabelle fuer Datenbankverbindungen, getrennt von `muwa_backup_repository` (Uebersicht,
  Wiederverwendung einer Verbindung in mehreren Backup-Konfigurationen).
- Parameter: `host`, `port`, `databaseName`, `type`, `username`, `password`, dazu ein `name`
  zur Anzeige.
- `type` zunaechst nur `mysql` (Anzeige "MySQL / MariaDB"). Weitere Typen kommen spaeter ueber
  separate Plugins.
- Passwort abgesichert wie das Restic-Repository-Passwort: `PasswordSource` `encrypted` / `env`
  / `file` ueber `SecretCipher` (`MUWA_FACILITY_SECRET`) bzw. den bestehenden Resolver.
- Aufloesung der Verbindung **und** Auswahl des Dumpers ueber ein Event, das den Datenbanktyp
  mitliefert. Ein Erweiterungs-Plugin kann so einen neuen Typ (z. B. PostgreSQL mit `pg_dump`)
  komplett selbst bedienen.
- Pruefen einer Verbindung aus der Administration ueber denselben Event-Weg.

## Nicht-Ziele

- Kein weiterer Datenbanktyp in diesem Plugin. Nur der Erweiterungspunkt.
- Kein Restore in eine Datenbank. Restore bleibt dateibasiert wie heute.
- Keine Aenderung am Dump-Pfad, an der Kompression oder am Files-Backup.
- Kein `plain`-Passwort fuer neue Verbindungen (`PasswordSource::PLAIN` ist nur Altbestand bei
  Repositories).
- Keine Verbindungsoptionen ueber die genannten Parameter hinaus (SSL, Socket, Charset) — kann
  spaeter ergaenzt werden.
- Die Table-Cleanup-Funktionen (`cart`, `log_entry`) bleiben auf der Systemverbindung.

## Anti-Ziele (Fehlschlag, auch wenn es "funktioniert")

- **Eine bestehende Backup-Konfiguration verhaelt sich nach dem Update anders.** Hoechste
  Prioritaet: ohne gewaehlte Verbindung muss exakt der heutige Weg (`DATABASE_URL`) laufen.
- Ein stiller Fallback auf die Systemdatenbank, wenn eine eigene Verbindung gewaehlt, aber nicht
  nutzbar ist. Dann wuerde unbemerkt die falsche Datenbank gesichert.
- Das Datenbank-Passwort taucht in API-Antworten, Logs, Message-Payloads, Prozess-Argumenten
  (`ps`) oder Fehlermeldungen auf.
- `CompleteFilesRunner` dumpt die Tabellen der fremden Datenbank anhand der Tabellenliste der
  Systemdatenbank (heute kommt `SHOW TABLES` aus der Doctrine-Systemverbindung).

## Entscheidungen

| Thema | Entscheidung | Quelle |
|---|---|---|
| Speicherort | Eigene Tabelle, Auswahl per Dropdown in der Backup-Konfiguration | Nutzer |
| Zuordnung | Pro Backup-Konfiguration, nicht global in der Plugin-Konfiguration | Nutzer |
| Admin | Eigene Eingabeseite mit Pruefen-Funktion | Nutzer |
| Standard | Dropdown enthaelt immer "Standardverbindung", initial nur diese | Nutzer |
| Altbestand | Bestehende Konfigurationen muessen kompatibel bleiben | Nutzer |
| Fehlerfall | Gewaehlte Verbindung fehlt/nicht erreichbar → Abbruch mit klarer Meldung, kein Fallback | Nutzer |
| Passwort | Wie Restic-Passwort (`PasswordSource`, `SecretCipher`) | Nutzer |
| Event | Liefert Typ mit; kann Verbindungsdaten **und** Dumper liefern | Nutzer |
| Standardverbindung | Kein Datensatz, sondern `NULL` in der FK-Spalte | eigene Entscheidung |
| Loeschen | Verbindung, die noch von einer Backup-Konfiguration genutzt wird, kann nicht geloescht werden | eigene Entscheidung |
| Fehlersemantik | Standardverbindung unveraendert; eigene Verbindung bricht bei jedem Fehler den ganzen Lauf ab | Nutzer |
| `env`/`file` | Env nur mit Praefix `MUWA_DB_`, Dateien nur unter `<projectDir>/var/secrets/` | Nutzer |
| Passwort Pflicht | DB-User ohne Passwort werden nicht unterstuetzt | eigene Entscheidung |

## Datenmodell

### Neue Tabelle `muwa_database_connection`

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | BINARY(16) PK | |
| `name` | VARCHAR(255) NOT NULL | Anzeigename im Dropdown |
| `type` | VARCHAR(32) NOT NULL | z. B. `mysql`; freier String, damit Plugins eigene Typen anlegen koennen |
| `host` | VARCHAR(255) NOT NULL | |
| `port` | INT NULL | leer = Standardport des Typs (MySQL 3306) |
| `database_name` | VARCHAR(255) NOT NULL | |
| `username` | VARCHAR(255) NOT NULL | |
| `password` | VARCHAR(512) NOT NULL | Chiffretext (`v1:`), Env-Variablenname oder Dateipfad; Passwort ist Pflicht |
| `password_source` | VARCHAR(16) NOT NULL | `encrypted` / `env` / `file` |
| `custom_fields` | JSON NULL | Platz fuer Erweiterungs-Plugins (zusaetzliche Typ-Parameter) |
| `created_at` / `updated_at` | DATETIME(3) | |

DAL-Definition `Core\Content\DatabaseConnection\DatabaseConnectionDefinition` analog zu
`BackupRepositoryDefinition`: `password` und `password_source` mit `removeFlag(ApiAware::class)`
bzw. `WriteProtected(Context::SYSTEM_SCOPE)`, geschrieben nur ueber einen eigenen Service
(Muster `Services\Content\BackupRepository::prepareStoredPassword()`).

### Erweiterung `muwa_backup_repository`

- Neue Spalte `database_connection_id BINARY(16) NULL DEFAULT NULL`, FK auf
  `muwa_database_connection.id` mit `ON DELETE RESTRICT`.
- `NULL` = Standardverbindung (heutiges Verhalten). Die Migration legt nur die Spalte an und
  aendert **keine** Daten — damit sind alle bestehenden Konfigurationen automatisch auf
  "Standardverbindung".
- Migration idempotent (`columnExists()`), Muster wie `Migration1793808551`.
- `ManyToOneAssociationField` `databaseConnection` + `FkField` in der Definition, Property im
  Entity, `BackupRepositorySettings` bekommt `?string $databaseConnectionId` (Default `null`).
- `Services\Backup` (Aufbau der Settings aus der Entity) uebernimmt die ID.
- `MuckiFacilityPlugin::dropPluginTables()` droppt die neue Tabelle **nach**
  `muwa_backup_repository` (FK-Reihenfolge).

## Aufloesung per Event

### Event `Events\ResolveDatabaseConnectionEvent`

Name `mucki_facility.database_connection.resolve`. Wird vor jedem Datenbank-Dump und bei jedem
Verbindungstest ausgeloest.

Eingaben (read-only):
- `getType(): string` — Datenbanktyp. Fuer die Standardverbindung `mysql` (abgeleitet aus dem
  Schema von `DATABASE_URL`).
- `getDatabaseConnection(): ?DatabaseConnectionEntity` — `null` = Standardverbindung.
- `getBackupSettings(): ?BackupRepositorySettings` — `null` beim Verbindungstest.
- `getContext(): Context`

Ausgaben (von Subscribern gesetzt):
- `setResolvedConnection(ResolvedDatabaseConnection $connection)` — Plain-Struct mit `type`,
  `host`, `port`, `databaseName`, `username`, `password` (Klartext, nur im Speicher) und
  `extra` (array, fuer typspezifische Parameter).
- `setDumper(DatabaseDumperInterface $dumper)` — erzeugt den Dump.
- `isResolved(): bool` — beides gesetzt.

Subscriber koennen per Prioritaet vorhandene Ergebnisse ueberschreiben (z. B. Passwort aus einem
Vault statt aus der Tabelle). `stopPropagation()` ist erlaubt.

### Interface `Backup\Database\DatabaseDumperInterface`

```php
interface DatabaseDumperInterface
{
    /** Tabellen der Zieldatenbank, fuer CompleteFilesRunner. */
    public function listTables(ResolvedDatabaseConnection $connection): array;

    /** Dump der ganzen Datenbank bzw. nur der uebergebenen Tabellen in $dumpFile. */
    public function dump(ResolvedDatabaseConnection $connection, string $dumpFile, array $tables = []): void;

    /** Verbindungstest; wirft bei Fehler eine Exception mit nutzerlesbarer Meldung. */
    public function testConnection(ResolvedDatabaseConnection $connection): void;
}
```

### Eingebauter Subscriber `Subscriber\MysqlDatabaseConnectionSubscriber`

- Reagiert nur auf `type === 'mysql'`.
- Standardverbindung (`null`): Verbindungsdaten aus `DATABASE_URL` (wie heute
  `Settings::getDatabaseUrl()`), Tabellenliste weiterhin ueber die Doctrine-Systemverbindung
  (`Core\Database\Database::getListOfAllTables()`) — verhaltensgleich zu heute.
- Eigene Verbindung: Passwort per Resolver aufloesen (`RepositoryPasswordResolver`, ggf. in
  einen allgemeinen `SecretResolver` umbenennen/verallgemeinern — Builder-Entscheidung, die
  Repository-Aufrufe muessen unveraendert bleiben).
- Dumper `MysqlDatabaseDumper`: `spatie/db-dumper` mit `setHost/setPort/setDbName/setUserName/
  setPassword`, `useSingleTransaction()` wie heute. spatie uebergibt das Passwort ueber eine
  temporaere Credentials-Datei, nicht als Argument — so beibehalten.
  `listTables()` / `testConnection()` ueber eine kurzlebige eigene DBAL-Verbindung
  (`DriverManager::getConnection`), danach schliessen.

### Umbau der Runner

`CompleteFileRunner` und `CompleteFilesRunner` loesen die Verbindung nicht mehr selbst auf,
sondern bekommen das Ergebnis aus dem Event (z. B. ueber einen Service
`Services\DatabaseConnectionResolver`, der das Event ausloest und bei `!isResolved()` bzw. bei
fehlender Verbindung eine `UnresolvableDatabaseConnectionException` wirft).
`CompleteFilesRunner` nutzt `$dumper->listTables()` statt `CoreDatabase`.
Der Dateiname enthaelt weiterhin den Datenbanknamen.

### Fehlerverhalten

Ist-Zustand im Code: `CompleteFileRunner`/`CompleteFilesRunner` fangen `CannotStartDump`/
`DumpFailed` und loggen nur — der leere/unvollstaendige Dump-Ordner wird trotzdem nach restic
geschoben, das Backup gilt als erfolgreich. Jede andere Exception wird von
`Services\Backup::startBackupRunner()` geloggt und **weitergeworfen** und bricht damit den ganzen
`createBackup()`-Lauf ab (kein Files-Backup, kein Check-Item, keine Stats, kein
`AfterBackupCreatedEvent`/Post-Command, Schedule-Status `failed`).

Entscheidung (Nutzer):

- **Standardverbindung (`NULL`):** Verhalten bleibt exakt wie heute, einschliesslich des
  stillen Loggens bei `CannotStartDump`/`DumpFailed` und des Weitermachens pro Tabelle.
- **Eigene Verbindung:** Jeder Fehler bricht den **ganzen Lauf** ab — Verbindung nicht
  auffindbar, Passwort nicht aufloesbar, kein Subscriber/Dumper fuer den Typ, Dump fehlgeschlagen.
  Bei `CompleteFilesRunner` bricht schon die erste fehlerhafte Tabelle ab. Es wird kein leerer
  oder unvollstaendiger Dump nach restic geschoben. Umsetzung ueber eine eigene Exception (z. B.
  `DatabaseBackupFailedException`), die durch den bestehenden `throw $e` in
  `startBackupRunner()` laeuft. Fehlermeldung nennt Verbindungsname und Ursache, nie das Passwort.
- **Kein** Fallback auf `DATABASE_URL` in irgendeinem Fehlerfall einer eigenen Verbindung.

### Einschraenkung von `env` / `file` (Exfiltrationsschutz)

Anders als beim Restic-Passwort geht das DB-Passwort an einen frei eintragbaren Host. Ohne
Einschraenkung koennte ein Admin-Nutzer `APP_SECRET` oder eine beliebige Server-Datei als
"Passwort" an einen eigenen Server schicken (Verbindung pruefen). Deshalb, nur fuer
Datenbankverbindungen (Repository-Passwoerter bleiben unveraendert):

- `env`: Variablenname muss mit `MUWA_DB_` beginnen (zusaetzlich zur bestehenden
  Namensvalidierung).
- `file`: Pfad muss nach `realpath()` unterhalb von `<projectDir>/var/secrets/` liegen; Symlinks
  und `..` werden dadurch abgefangen.
- Geprueft beim Speichern **und** beim Aufloesen (Backup-Lauf, Verbindungstest), damit auch
  direkt in die Datenbank geschriebene Werte abgewiesen werden.

## Administration

- Zweiter Tab **Datenbankverbindungen** auf der bestehenden Listen-Seite
  (`muwa.backup.repository.index`, `tab=databaseConnectionList`) plus eigene Routen
  `database-connection/create` und `database-connection/detail/:id`.
- Formular: Name, Typ (Select, Optionen `mysql` → "MySQL / MariaDB"; per Snippet/Registry
  erweiterbar), Host, Port, Datenbankname, User, Passwort-Quelle + Passwort — Passwort-UI wie auf
  der Repository-Create-Seite. Passwort wird nie zurueck an die Administration geliefert; im
  Detail bleibt das Feld leer, leer speichern = Passwort unveraendert.
- Button **Verbindung pruefen**: `POST /api/_action/muwa/database-connection/test`. Akzeptiert
  entweder die ID einer gespeicherten Verbindung oder die ungespeicherten Formularwerte (Passwort
  aus dem Formular, sonst das gespeicherte). Antwort: ok / Fehlermeldung des Dumpers (ohne
  Passwort).
- Schreiben ueber eigene Action-Route (`.../database-connection/save`), da `password`/
  `password_source` WriteProtected sind — Muster `InitBackupRepositoryController`.
- Backup-Konfiguration (Create + Detail-Tab Configuration): Dropdown `databaseConnectionId`,
  erster Eintrag "Standardverbindung" mit Wert `null`. 6.6/6.7-Select-Muster aus der Plugin-
  `CLAUDE.md` beachten. Nur sichtbar, wenn der Backup-Typ einen Datenbank-Dump enthaelt.
- Loeschen einer genutzten Verbindung: API liefert Fehler, Administration zeigt die Namen der
  nutzenden Backup-Konfigurationen.
- ACL: neue Privilegien `muwa_database_connection:*` in die bestehenden Rollen von
  `muwa_backup_repository` (viewer: read, editor: update + test, creator: create, deleter:
  delete) — keine neue Rollengruppe (eigene Entscheidung).
- Snippets de-DE / en-GB.

## Kompatibilitaet

- Shopware 6.6 und 6.7, DBAL 3 und 4 (siehe Regeln in `CLAUDE.md`).
- Ohne Aenderung der Konfiguration bleibt jede bestehende Backup-Konfiguration auf
  `database_connection_id = NULL` und laeuft ueber den Standardweg.
- `BeforeDatabaseDumpCreatedEvent` / `AfterDatabaseDumpCreatedEvent` bleiben unveraendert.
- CLI `muckiware:db:dump` nutzt weiterhin die Standardverbindung.

## Tests (Unit, `phpunit.unit.xml`)

- Migration: Spalte nullable, Altdaten unveraendert, zweiter Lauf no-op.
- Resolver: `null` → Standardverbindung aus `DATABASE_URL`; gesetzte ID → eigene Daten;
  kein Subscriber fuer Typ → Exception; unaufloesbares Passwort → Exception, kein Fallback.
- Event: Plugin-Subscriber mit hoeherer Prioritaet ueberschreibt Verbindung und Dumper.
- `CompleteFilesRunner` nutzt die Tabellenliste des Dumpers, nicht der Systemverbindung.
- Passwort taucht in keiner Exception-Meldung und keinem Log-Eintrag auf.
- Eigene Verbindung: Dump-Fehler bricht den Lauf ab, kein Files-Backup des leeren Dump-Ordners;
  Standardverbindung: Dump-Fehler verhaelt sich wie heute.
- `env` ohne Praefix `MUWA_DB_` und `file` ausserhalb von `var/secrets/` (inkl. Symlink, `..`)
  werden abgewiesen.

## Dem Builder ueberlassen

- Genaue Klassen- und Dateinamen, Aufteilung Service/Subscriber.
- Ob `RepositoryPasswordResolver` verallgemeinert oder ein zweiter Resolver auf `SecretCipher`
  gebaut wird (der `KEY_CONTEXT` des Ciphers darf sich fuer Repository-Passwoerter nicht aendern).
- Versionsbump und `CHANGELOG.md`, Ergaenzung der Plugin-`CLAUDE.md`.
