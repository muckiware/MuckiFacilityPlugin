# S3-Spiegelung von Backup-Repositories

Design-Dokument, 16.11.2026. Plugin `muckiware/facility-plugin`.

## Warum

Ein Backup soll an zwei Orten liegen: lokal und auf einem S3-Server. Das S3-Backend von restic
direkt zu nutzen hiesse, jedes Backup zweimal laufen zu lassen (einmal lokal, einmal gegen S3) —
doppelte Laufzeit bei jedem Backup. Stattdessen laeuft restic wie bisher **einmal** lokal, und
danach wird das fertige, gepruefte Repository-Verzeichnis 1:1 in einen S3-Bucket gespiegelt
(sinngemaess `rclone sync <repository> <remote>:<bucket>`). Ergebnis: zwei Kopien desselben
Repositories an zwei Orten, ohne zweiten restic-Lauf.

Die restic-eigene S3-Faehigkeit von `muckiware/restic` wird bewusst **nicht** verwendet.

## Ablauf aus Nutzersicht

1. Im Backup-Modul gibt es einen neuen Tab **S3-Verbindungen** (analog zu
   **Datenbankverbindungen**). Dort legt man Verbindungen an: Name, Provider, Endpoint, Region,
   Access Key ID, Secret Access Key. Ein Button **Verbindung pruefen** testet den Zugang.
2. In der Backup-Konfiguration (Create-Seite und Detail-Tab Configuration) waehlt man optional
   eine S3-Verbindung und gibt Bucket und Pfad (Prefix) an. Ohne Verbindung keine Spiegelung —
   alles wie bisher.
3. Jedes Backup laeuft lokal wie bisher. Nach `restic check`:
   - Check gruen → das Repository-Verzeichnis wird in den Bucket gespiegelt.
   - Check nicht gruen → die Spiegelung wird uebersprungen (die gute S3-Kopie bleibt erhalten).
4. Ergebnis jeder Spiegelung ist sichtbar: im Dashboard-Widget (Spalte "S3") und als Historie
   auf der Detailseite. Erfolg und Misserfolg feuern je ein Event, das im Shopware Flow Builder
   als Ausloeser waehlbar ist (spaeter: Mailversand).
5. Per CLI `muckiware:repository:upload <id>` laesst sich die Spiegelung einzeln nachholen.

```
Repository    | Letzter Check        | S3
Shop-Prod     | ✔ no errors found    | ✔ 16.11. 03:12
Paperless     | ✔ no errors found    | ✖ 16.11. 03:40 – Access denied
```

## Ziele

- Spiegelung eines lokalen restic-Repositories in einen S3-kompatiblen Bucket, in reinem PHP
  ueber `aws/aws-sdk-php`.
- Echte Spiegelung: fehlende Dateien hochladen, remote ueberzaehlige Dateien loeschen. Nach
  `forget`/`prune` muss die S3-Kopie ein konsistentes, von restic direkt lesbares Repository sein.
- Spiegelung nur nach gruenem Check.
- Zentrale, wiederverwendbare S3-Verbindungen mit sicher abgelegtem Secret.
- Sichtbarer Upload-Status (Historie, Dashboard).
- Flow-Builder-faehige Events fuer Erfolg und Misserfolg.
- CLI-Befehl zum Nachholen.

## Nicht-Ziele

- Kein Mailversand, kein Mail-Template-Typ, kein vorkonfigurierter Flow — nur die Events.
- Kein Restore aus S3 durch das Plugin (die S3-Kopie ist ein normales restic-Repository; Restore
  geht mit restic direkt oder nach Rueckkopie).
- Kein Button "Jetzt spiegeln" in der Administration (spaeter moeglich).
- Keine parallelen Uploads in der ersten Version.
- Kein rclone, kein anderes externes Programm, keine eigenen `Process`/`exec`-Aufrufe.
- Kein Spiegeln nach `forget`/Snapshot-Loeschung aus der Administration — die naechste Spiegelung
  nach dem naechsten Backup gleicht das an.

## Anti-Ziele (Fehlschlag, auch wenn es "funktioniert")

- Ein beschaedigtes lokales Repository ueberschreibt die gute S3-Kopie.
- Ein falscher, leerer oder nicht gemounteter lokaler Pfad fuehrt dazu, dass im Bucket alles
  geloescht wird.
- Fremde Dateien im Bucket (ausserhalb des restic-Layouts) werden geloescht.
- Der Secret Access Key taucht in Logs, Exceptions, API-Antworten, Messages, der Upload-Historie
  oder Event-Daten auf.
- Ein gescheiterter Upload laesst das lokale Backup als gescheitert erscheinen oder bricht
  `createBackup()` ab.
- Ein gescheiterter oder seit Tagen ausbleibender Upload bleibt unbemerkt.

## Ansaetze (betrachtet)

| Ansatz | Plus | Minus | Entscheidung |
|---|---|---|---|
| **A) rclone** per Symfony `Process`, Secrets per `RCLONE_CONFIG_*`-Env | erprobter Sync, parallel, Multipart, `provider` passt 1:1 | rclone auf jedem Server noetig (im ddev fehlt es), zweites Binary, das versioniert und geprueft werden will (die Plugin-Regel gegen eigene `Process`-Aufrufe gilt nur fuer restic; `Process` wird im Plugin schon fuer Hooks/Dumps genutzt) | verworfen |
| **B) PHP mit `aws/aws-sdk-php`** | nichts auf dem Server zu installieren, Secret bleibt im PHP-Prozess, passt zum Plugin; Sync ist einfach, weil restic-Dateien unveraenderlich sind (Name = Hash) | eigener Code, Parallelitaet selbst zu bauen falls noetig | **gewaehlt** |
| **C) Flysystem S3-Adapter** | Abstraktion | Adapter nicht installiert, zusaetzliche Abhaengigkeit, Sync-Logik trotzdem selbst | verworfen |

## Entscheidungen

Vom Nutzer entschieden:

- Spiegelung nach dem Check, kein zweiter restic-Lauf, nicht das restic-S3-Backend.
- Eigener Tab fuer S3-Verbindungen analog zu den Datenbankverbindungen.
- Parameter der Verbindung: provider, access_key_id, secret_access_key, endpoint.
- Echte Spiegelung (remote loeschen, was lokal fehlt).
- Nur nach gruenem Check spiegeln.
- Verbindung zentral, Bucket + Pfad in der Backup-Konfiguration.
- Upload-Status sichtbar: Historie + Dashboard-Spalte.
- Events bei Erfolg und Misserfolg, im Flow Builder nutzbar; Mail kommt spaeter.
- CLI-Befehl zum Nachholen; kein Admin-Button.
- Spiegelung immer automatisch nach dem regulaeren Backup, auch die erste — nicht als manueller
  CLI-Schritt.
- Umsetzung mit Ansatz B.

Vom Designer gesetzt (siehe "Calls des Designers" am Ende).

## Datenmodell

### Neue Tabelle `muwa_s3_connection`

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | BINARY(16) PK | |
| `name` | VARCHAR(255) NOT NULL | Anzeige |
| `provider` | VARCHAR(32) NOT NULL | Enum `Core\S3Providers`: `aws`, `minio`, `ceph`, `other` (Anzeige z.B. "Andere S3-kompatible") |
| `endpoint` | VARCHAR(512) NULL | Pflicht ausser bei `aws`; nur `https://` (bei `minio`/`other` auch `http://`, siehe Builder) |
| `region` | VARCHAR(64) NULL | Pflicht bei `aws`; sonst Default `us-east-1`, wenn leer |
| `access_key_id` | VARCHAR(255) NOT NULL | nicht geheim, aber nicht ApiAware-relevant |
| `secret_access_key` | VARCHAR(512) NULL | verschluesselt / Referenz, `removeFlag(ApiAware)` |
| `secret_source` | VARCHAR(16) NOT NULL | `encrypted` / `env` / `file` (`Core\PasswordSource`) |
| `custom_fields` | JSON NULL | |
| `created_at` / `updated_at` | | |

- **Alle** Felder `WriteProtected(SYSTEM_SCOPE)`; geschrieben wird nur ueber
  `Services\Content\S3ConnectionRepository` (wie `DatabaseConnectionRepository`). Begruendung wie
  dort: sonst koennte ein Nutzer mit `update`-Recht Endpoint/Key-ID umbiegen und bekaeme das
  gespeicherte Secret an einen fremden Server geschickt.
- Aenderung von `provider`, `endpoint`, `region`, `access_key_id` oder `secret_source` verlangt ein
  neu eingegebenes Secret (gleiche Regel wie bei DB-Verbindungen).
- `OneToManyAssociationField('backupRepositories', ..., 'mirror_s3_connection_id')` mit
  `RestrictDelete`.
- Zeilenumbrueche/NUL in allen Feldern abweisen (beim Speichern und beim Aufloesen).

### Secret-Aufloesung

Neuer `Services\Content\S3SecretResolver` nach dem Muster von `DatabasePasswordResolver`:
Quellen `encrypted` (`SecretCipher`), `env` (nur Praefix **`MUWA_S3_`**), `file` (nur unterhalb
`<projectDir>/var/secrets/`, `realpath()`). Kein `plain`. Gemeinsame Logik mit dem
DB-Resolver darf der Builder in eine Basisklasse ziehen, solange die Praefix-Regel je Resolver
eigen bleibt und die bestehenden DB-Tests unveraendert gruen sind.

### Erweiterung `muwa_backup_repository`

| Spalte | Typ | Hinweis |
|---|---|---|
| `mirror_s3_connection_id` | BINARY(16) NULL, FK → `muwa_s3_connection.id` `ON DELETE RESTRICT` | NULL = keine Spiegelung |
| `mirror_s3_bucket` | VARCHAR(255) NULL | Pflicht, wenn Verbindung gesetzt; S3-Bucket-Namensregeln pruefen |
| `mirror_s3_prefix` | VARCHAR(1024) NULL | optional; normalisiert ohne fuehrenden, mit abschliessendem `/`; keine `..`-Segmente |

Alle drei Felder `WriteProtected(SYSTEM_SCOPE)`, geschrieben nur ueber eine eigene Route
`POST /api/_action/muwa/backup/{backupRepositoryId}/mirror` (Muster:
`BackupRepositoryHooksController`), ACL `muwa_backup_repository:update`. Begruendung: Die
Spiegelung loescht remote. Waeren Bucket/Prefix per generischer DAL frei schreibbar, koennte jeder
mit `update`-Recht die gespeicherten Credentials auf einen fremden Bucket richten und dort Daten
loeschen lassen — dasselbe Bedrohungsmodell wie bei den Verbindungen. Die Detailseite speichert
heute per generischem `repository.save()`; der S3-Abschnitt speichert deshalb separat ueber die
neue Route (wie die Hooks). Auf der Create-Seite: nach dem Anlegen ueber dieselbe Route.

Validierung in der Route (und erneut zur Laufzeit):
- Bucket nach S3-Namensregeln, Prefix normalisiert, keine `..`-Segmente.
- **Keine ueberlappenden Ziele:** Zwei Repositories duerfen nicht auf dasselbe Ziel zeigen, wenn
  Endpoint (normalisiert) + Bucket gleich sind und ein Prefix gleich dem anderen ist oder mit ihm
  beginnt (auch leerer Prefix ueberlappt mit allem im Bucket). Gleiche Endpoints ueber
  verschiedene Verbindungen gelten als gleiches Ziel. Fehler 400 mit Name der kollidierenden
  Konfiguration.

### Neue Tabelle `muwa_backup_repository_uploads`

Historie je Spiegelungsversuch, n:1 zum Repository (`ON DELETE CASCADE`).

| Spalte | Typ |
|---|---|
| `id`, `backup_repository_id` | BINARY(16) |
| `status` | VARCHAR(16): `succeeded` / `failed` / `skipped` |
| `trigger_source` | VARCHAR(16): `backup` / `cli` (nicht `trigger` — reserviertes Wort in MySQL) |
| `bucket`, `prefix`, `s3_connection_name` | Momentaufnahme des Ziels (ohne Secrets) |
| `started_at`, `finished_at` | DATETIME(3) |
| `files_uploaded`, `files_deleted`, `files_unchanged` | INT |
| `bytes_uploaded` | BIGINT |
| `message` | LONGTEXT NULL — Fehlertext bzw. Skip-Grund, bereinigt (siehe Fehlerverhalten) |
| `created_at`, `updated_at` | |

Aufbewahrung: beim Schreiben eines neuen Eintrags nur die letzten **100** Eintraege je Repository
behalten (aeltere loeschen).

Alle drei Tabellen in `MuckiFacilityPlugin::dropPluginTables()` aufnehmen, in FK-sicherer
Reihenfolge (`uploads`, dann `muwa_backup_repository`, dann `muwa_s3_connection`). Migrationen per
`columnExists()`-Checks idempotent wie die bestehenden.

## Spiegelungs-Algorithmus (`Services\RepositoryMirror`)

Einstieg `mirror(string $backupRepositoryId, string $trigger): MirrorResult`. Wirft nie nach
aussen; jedes Ergebnis wird als Historien-Eintrag gespeichert und als Event gefeuert.

1. **Ziel aufloesen:** Repository laden, Verbindung laden, Secret aufloesen, `S3Client` bauen
   (`endpoint`, `region`, `use_path_style_endpoint` = true bei `minio`/`ceph`/`other`, false bei
   `aws`, Credentials aus Key-ID + Secret). Ohne Verbindung: nichts tun, kein Eintrag.
2. **Sicherheitsbremse lokal:** Repository-Pfad muss existieren und ein restic-Repository sein:
   Datei `config` vorhanden **und** `keys/` nicht leer. Sonst `failed` ohne jegliche Remote-Aenderung.
   **Kein laufender restic-Vorgang:** liegt in `locks/` eine Datei, `failed` ("Repository gesperrt")
   ohne Remote-Aenderung. Gleiche Pruefung nochmals unmittelbar vor Schritt 6. Schuetzt gegen
   gleichzeitiges `forget --prune` (Cron), Snapshot-Loeschung aus der Administration und den
   Takeover-Check, die den Plugin-Lock nicht nehmen — bestehende Pfade bleiben unveraendert.
   Restrisiko (Lock entsteht zwischen den Pruefungen) wird akzeptiert; der naechste Lauf gleicht an.
2a. **Gleiches Repository remote:** existiert remote `prefix + config`, wird es heruntergeladen
   (klein) und byteweise mit der lokalen `config` verglichen. Abweichung → `failed`
   ("anderes Repository am Ziel") ohne Remote-Aenderung. So kann ein re-initialisiertes oder
   umgehaengtes lokales Repository die alte Kopie nicht zerstoeren; aufloesen muss das ein Mensch
   (Ziel aendern oder Bucket-Pfad leeren).
3. **Lokal auflisten:** rekursiv unter dem Repository-Pfad, nur regulaere Dateien, Symlinks
   ueberspringen, `locks/` auslassen. Schluessel = `prefix` + relativer Pfad (immer `/`).
4. **Remote auflisten:** `ListObjectsV2` paginiert unter `prefix`. Nur Schluessel innerhalb des
   restic-Layouts gelten als "verwaltet": `config`, `keys/…`, `data/…`, `index/…`, `snapshots/…`.
   Alles andere wird nie angefasst (auch `locks/…` remote nicht).
5. **Hochladen**, was remote fehlt oder abweichende Groesse hat, in der Reihenfolge `data/` →
   `index/` → `keys/` → `snapshots/` → `config`. Damit ist der Remote-Stand bei einem Abbruch
   mittendrin so konsistent wie moeglich (kein Snapshot ohne Index, kein Index ohne Packs).
   Grosse Dateien per `ObjectUploader` (Multipart ab z.B. 64 MiB).
6. **Loeschen**, erst wenn alle Uploads erfolgreich waren: verwaltete Remote-Schluessel ohne lokale
   Entsprechung, in umgekehrter Reihenfolge `snapshots/` → `index/` → `keys/` → `data/`.
   `config` wird nie geloescht. Batches ueber `DeleteObjects`.
7. **Ergebnis** zaehlen (hochgeladen/geloescht/unveraendert/Bytes), Eintrag schreiben, Event feuern.

Vergleich nur ueber Schluessel + Groesse: restic-Dateien sind nach dem Schreiben unveraenderlich
und nach ihrem Inhalts-Hash benannt (Ausnahme `config`, das sich ebenfalls nie aendert). ETags
werden nicht verglichen (bei Multipart kein MD5).

## Einbindung in den Backup-Ablauf

`Services\Backup::createBackup()`:

```
runDatabaseBackup → runFilesBackup → createCheckItem → [NEU] dispatch MirrorRepositoryMessage
→ saveSnapshots → collectAndSave → AfterBackupCreatedEvent (→ Post-Backup-Command)
                       ⋮ (async, eigener Handler)
              MirrorRepositoryHandler → RepositoryMirror::mirror($id, 'backup')
```

**Vom Nutzer entschieden:** Die Spiegelung laeuft immer automatisch nach dem regulaeren Backup,
auch die erste, grosse — kein separater CLI-Schritt als Voraussetzung.

**Umsetzung als eigene Message.** Liefe die Spiegelung im selben Handler wie das Backup, wuerde
eine lange Erstspiegelung den `redeliver_timeout` des Doctrine-Transports (Standard 3600 s)
reissen; die Backup-Message wuerde erneut zugestellt und das **Backup** liefe ein zweites Mal.
Deshalb dispatcht `createBackup()` direkt nach `createCheckItem()` eine
`MirrorRepositoryMessage` (`AsyncMessageInterface`, traegt nur `backupRepositoryId` und die
ID des gerade geschriebenen Check-Eintrags — keine Secrets). Das Backup endet wie bisher, ohne
auf die Spiegelung zu warten.

- Gilt fuer alle Backup-Wege (Zeitplan, Admin-Button, `muckiware:backup:create`). Bei der CLI
  laeuft die Spiegelung damit im Worker; ohne laufenden Worker findet sie nicht statt. Ist das
  im Projekt ein Problem, kann der Builder `muckiware:backup:create` die Message synchron
  verarbeiten lassen (Option `--mirror-sync` o.ae.) — nicht Teil dieses Designs.
- Der Handler prueft den mitgegebenen Check-Eintrag: nicht gruen oder nicht gefunden →
  `skipped`. "Gruen" = gleiche Regel wie das Dashboard-Widget (`no errors were found`); als eine
  zentrale PHP-Methode ablegen, damit Spiegelung und Anzeige nicht auseinanderlaufen.
  `createCheckItem()` liefert dazu kuenftig ID + Ergebnis des geschriebenen Eintrags zurueck.
- **Kein doppelter Lauf:** Der Handler nimmt einen Lock `mucki-facility.mirror.<repositoryId>`
  (nicht blockierend, TTL deutlich ueber der erwarteten Laufzeit bzw. per `refresh()` waehrend
  des Uploads verlaengert). Ist er belegt (z.B. Neuzustellung nach `redeliver_timeout`, waehrend
  die erste Zustellung noch laedt), endet der Handler still ohne Fehler und ohne Retry; kein
  Historien-Eintrag, kein Event. Der CLI-Befehl nimmt denselben Lock.
- **Abgebrochene Spiegelung setzt fort:** Weil nur fehlende Dateien hochgeladen werden, macht der
  naechste Lauf dort weiter, wo ein abgebrochener aufgehoert hat. Eine Erstspiegelung, die
  mehrere Backup-Laeufe braucht, wird so ohne Sonderbehandlung fertig (bis dahin `failed`-Eintraege
  mit Zaehlerstand bzw. Abbruchgrund).
- Ein inzwischen laufendes Folge-Backup desselben Repositories erkennt die Spiegelung an den
  restic-Locks in `locks/` (siehe Algorithmus, Schritt 2) und bricht ohne Remote-Aenderung ab.
- Admin-Hinweis in der Detailseite, solange es noch keinen `succeeded`-Eintrag gibt:
  "Erste Spiegelung laeuft bzw. steht aus — kann bei grossen Repositories mehrere Stunden dauern".

## CLI `muckiware:repository:upload <backupRepositoryId> [--force]`

- Nimmt denselben Lock `mucki-facility.backup` wie `Schedule\BackupExecution`; ist er belegt,
  Abbruch mit Meldung.
- Prueft den letzten gespeicherten Check (`muwa_backup_repository_checks`). Nicht gruen → `skipped`,
  es sei denn `--force`.
- Ruft `RepositoryMirror::mirror($id, 'cli')`. Exit-Code 0 bei `succeeded`, sonst 1.
- Registrierung in `services.xml` mit `console.command` wie alle Commands.

## Fehlerverhalten

- Spiegelungsfehler beruehren das Backup nicht (eigene Message); das lokale Backup gilt als
  erfolgreich. Der `MirrorRepositoryHandler` wirft nie (wie `TakeoverRepositoryCheckHandler`),
  damit der Messenger nicht automatisch wiederholt.
  `BackupExecution` meldet weiter "Backup erfolgreich"; die Spiegelung meldet sich separat:
  zusaetzliche Admin-Benachrichtigung (`AdminNotification`) bei `failed`/`skipped`.
- Fehlermeldungen aus dem SDK werden bereinigt gespeichert: nur AWS-Fehlercode + Kurztext
  (`AwsException::getAwsErrorCode()` / `getAwsErrorMessage()`), keine Request-Header, keine
  Signatur, kein Secret. Generische Exceptions: Klassenname + Message, vor dem Speichern auf
  Vorkommen von Key-ID-fremden Secret-Werten pruefen und ersetzen.
- Ein Fehler mitten im Upload: Schritt 6 (Loeschen) entfaellt, Eintrag `failed` mit Zaehlerstand.

## Events (Flow Builder)

Zwei neue Events in `src/Events/`:

- `RepositoryUploadSucceededEvent` — Name `mucki_facility.repository_upload.succeeded`
- `RepositoryUploadFailedEvent` — Name `mucki_facility.repository_upload.failed`
  (auch fuer `skipped`)

Beide implementieren `FlowEventAware`, `MailAware` und `ScalarValuesAware` und werden per
Subscriber auf `BusinessEventCollectorEvent` registriert, damit sie im Flow Builder in der
Ausloeser-Auswahl erscheinen (ausgefuehrt wuerden Flows auch ohne). Achtung:
`BusinessEventCollector::define()` instanziiert per `newInstanceWithoutConstructor()` —
`getName()` und `getAvailableData()` duerfen keinen Konstruktor-Zustand nutzen. Labels der
Ausloeser als Snippets unter `sw-flow.triggers.*` (de-DE/en-GB). `MailAware::getMailStruct()` liefert einen leeren Empfaenger-Struct (Empfaenger werden
spaeter im Flow als Admin/eigene Adresse gewaehlt), `getSalesChannelId()` `null`.

Daten (`getAvailableData()` / Scalar-Values): `backupRepositoryId`, `backupRepositoryName`,
`status`, `bucket`, `prefix`, `s3ConnectionName`, `startedAt`, `finishedAt`, `filesUploaded`,
`filesDeleted`, `bytesUploaded`, `message`. **Keine** Secrets, kein Endpoint mit Credentials.

Dispatch mit `Context::createDefaultContext()` (wie `BackupExecution`). Die Events muessen in
6.6 und 6.7 im Flow Builder erscheinen.

## Administration

- **Tab S3-Verbindungen** im Index (`muwa.backup.repository.index`, Tab `s3ConnectionList`) mit
  Liste + Detailseite (`s3ConnectionCreate` / `s3ConnectionDetail`), aufgebaut wie die
  Datenbankverbindungs-Seiten. Secret-Feld nie vorbelegt; Quelle `encrypted`/`env`/`file`.
- **Verbindung pruefen:** Route `POST /api/_action/muwa/s3-connection/test` mit optionalem
  Bucket im Dialog. Mit Bucket `HeadBucket`, ohne `ListBuckets`. Antwort wie beim DB-Test
  (400 bei ungueltigen Feldern, 200 + `success=false` bei Zugangsfehlern, bereinigte Meldung).
- **Speichern:** `POST /api/_action/muwa/s3-connection/save`, ACL `muwa_s3_connection:update`,
  Neuanlage zusaetzlich `:create`. Rollen `muwa_s3_connection:*` haengen in den bestehenden
  `muwa_backup_repository`-Rollen (wie bei DB-Verbindungen). Loeschen einer genutzten Verbindung
  scheitert an `RestrictDelete`; die Administration zeigt die nutzenden Konfigurationen.
- **Backup-Konfiguration** (Create + Detail-Tab Configuration): Abschnitt "S3-Spiegelung" mit
  Dropdown (erster Eintrag "Keine Spiegelung"), Bucket, Pfad.
- **Detail-Tab Repository-Status** (`backupRepositoryStats`): Abschnitt "S3-Spiegelung" mit
  den letzten Eintraegen aus `muwa_backup_repository_uploads` (Zeit, Status, Dateien, Bytes,
  Meldung) und "zuletzt erfolgreich gespiegelt am".
- **Dashboard-Widget:** Spalte "S3" mit letztem Eintrag (Association mit `setLimit(1)`, wie
  bei Stats) — gruen `succeeded`, rot `failed`, grau `skipped`/kein Eintrag; Tooltip mit Datum
  des letzten Erfolgs. Spalte nur, wenn mindestens ein Repository eine Spiegelung hat.
- Snippets de-DE/en-GB modul-lokal, Platzhalter per `$t(key, {...})`.
- Vue-2-Style wie der Rest des Moduls; 6.6 und 6.7 parallel (siehe CLAUDE.md).

## Abhaengigkeiten & Kompatibilitaet

- `aws/aws-sdk-php: ^3.369` ausdruecklich in `composer.json` des Plugins aufnehmen (heute nur
  transitiv ueber `muckiware/restic`).
- Shopware 6.6 + 6.7, PHP-Range laut `composer.json`.
- Version bumpen, `CHANGELOG.md` und `README.md` ergaenzen, Plugin-`CLAUDE.md` um Abschnitt
  S3-Spiegelung erweitern.

## Tests

Unit (`phpunit.unit.xml`, ohne Shopware-Bootstrap):
- Sync-Plan: Upload-/Loesch-Mengen und Reihenfolge aus lokaler + Remote-Liste; `locks/` und
  Fremd-Schluessel bleiben unberuehrt; `config` wird nie geloescht.
- Sicherheitsbremse: fehlendes `config` / leeres `keys/` → keine Remote-Aenderung.
- Kein Loeschen nach fehlgeschlagenem Upload.
- restic-Lock in `locks/` → Abbruch ohne Remote-Aenderung; abweichende Remote-`config` → Abbruch.
- Ueberlappungspruefung der Ziele (gleicher Prefix, Prefix-in-Prefix, leerer Prefix).
- `createBackup()` dispatcht `MirrorRepositoryMessage` nur bei gesetzter Verbindung, ohne Secrets.
- Handler: belegter Mirror-Lock → stilles Ende ohne Eintrag; roter Check → `skipped`.
- Prefix-Normalisierung und Bucket-Validierung.
- `S3SecretResolver`: Quellen, `MUWA_S3_`-Praefix, `var/secrets`-Grenze, Zeilenumbruch/NUL.
- Fehlermeldungs-Bereinigung enthaelt kein Secret.
- "Check gruen"-Regel.

Mit Bootstrap:
- S3-Client per `MockHandler` des SDK (kein echter Server): kompletter Lauf, Historien-Eintrag,
  Events gefeuert mit erwarteten Daten.
- Events erscheinen in der `BusinessEventCollector`-Liste.
- `RouteAclTest` um die neuen Routen ergaenzen; Secret nie in API-Antwort.

Manuell: einmal gegen einen echten S3-kompatiblen Server (z.B. MinIO-Container im ddev) inkl.
`restic -r s3:… check` gegen die gespiegelte Kopie.

## Dem Builder ueberlassen

- Klassenschnitt innerhalb `RepositoryMirror` (z.B. Sync-Planer als reine Funktion, damit er
  ohne SDK testbar ist).
- Ob `http://`-Endpoints fuer `minio`/`other` erlaubt werden (Default: erlaubt, Hinweis in der UI).
- Multipart-Schwelle und Batch-Groessen.
- Ob die gemeinsame Secret-Logik in eine Basisklasse wandert.

## Calls des Designers (vom Nutzer nicht ausdruecklich entschieden)

- Feld `region` zusaetzlich zu den genannten Parametern (das SDK braucht sie; Pflicht bei `aws`,
  sonst Default `us-east-1`).
- Bucket/Prefix am Repository `WriteProtected`, Speichern ueber eigene Route; Ueberlappungspruefung
  der Ziele ueber alle Repositories.
- Abbruch ohne Remote-Aenderung bei restic-Lock im lokalen Repository und bei abweichender
  Remote-`config`.
- Provider-Liste `aws`/`minio`/`ceph`/`other`; Provider steuert Path-Style.
- Secret-Quellen wie bei DB-Verbindungen, Env-Praefix `MUWA_S3_`.
- Spaltennamen mit Praefix `mirror_s3_` am Repository.
- Historie auf 100 Eintraege je Repository begrenzt.
- `skipped` loest das `failed`-Event aus (mit `status=skipped`).
- Spiegelungsfehler brechen das Backup nicht ab; separate Admin-Benachrichtigung.
- Nur Schluessel im restic-Layout werden remote verwaltet/geloescht; `config` nie.
- CLI nimmt den Backup-Lock und prueft den letzten Check, `--force` zum Uebergehen.
- Historie im Tab Repository-Status statt eigenem Tab.
- Spiegelung als eigene async Message direkt nach dem Check (statt im Backup-Handler), damit eine
  lange Erstspiegelung keine Neuzustellung und damit kein zweites Backup ausloest; eigener Lock je
  Repository gegen doppelte Spiegelung.
