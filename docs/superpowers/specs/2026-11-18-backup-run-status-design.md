# Backup-Laufstatus in der Repository-Liste

Design-Dokument, 18.11.2026. Plugin `muckiware/facility-plugin`.

## Warum

Heute sieht man im Admin nicht, ob gerade ein Backup laeuft. Es gibt nur drei Admin-Benachrichtigungen
(gestartet / erfolgreich / fehlgeschlagen). Die Zeit zwischen Klick und Abholung durch den Worker ist
unsichtbar, und ein manueller Start waehrend eines laufenden Backups scheitert still
(`BackupAlreadyRunningException` → Messenger-Retries → failed transport).

Ziel: In der Repository-Liste steht pro Repository, ob ein Backup **wartet** oder **laeuft**, und in
einer zweiten Zeile der aktuelle **Schritt** (DB-Dump, Pfad 2/5, Check …). Die Anzeige aktualisiert
sich selbst, ohne Neuladen der Seite.

## Ablauf aus Nutzersicht

1. Auf der Detailseite klickt man **Backup starten**. Der Lauf erscheint sofort als `waiting`.
2. Holt der Worker die Nachricht ab und ist kein anderes Backup aktiv, wechselt der Lauf auf
   `running`, die zweite Zeile zeigt den Schritt.
3. Laeuft schon ein anderes Backup (globaler Lock), bleibt der Lauf `waiting` und wird automatisch
   spaeter erneut versucht, bis der Lock frei ist.
4. Am Ende steht das Ergebnis des letzten Laufs (`succeeded` / `failed` / `skipped` / `aborted`).

```
Name         | Aktiv | Backup-Status            | Letzter Check | Letztes Backup
Shop-Prod    |  ✔    | [running]                | ✔ no errors   | 18.11. 03:12
             |       | Dateien: Pfad 2/5        |               |
Paperless    |  ✔    | [waiting]                | ✔ no errors   | 17.11. 03:40
             |       | wartet auf freien Slot   |               |
Archiv       |  ✔    | [succeeded] 18.11. 02:10 | ✔ no errors   | 18.11. 02:10
```

## Ziele

- Persistenter Status pro Backup-Lauf in eigener Tabelle `muwa_backup_run`.
- Status `waiting` / `running` plus aktueller Schritt in der Repository-Liste, automatisch aktualisiert.
- Manuelle Starts waehrend eines laufenden Backups werden **eingereiht**, nicht verworfen.
- Ein abgestuerzter Worker hinterlaesst keinen ewigen `running`-Status.

## Nicht-Ziele

- **Keine Prozentanzeige** und keine Aenderung an `muckiware/restic`. Die kommt in einer spaeteren
  Umsetzung; die Tabelle bekommt dann eine Spalte dazu.
- Keine Statusanzeige auf Dashboard oder Detailseite (spaeter moeglich, gleicher Endpoint).
- Keine Echtzeit-Push-Technik (Mercure, SSE, WebSocket).
- Keine Lauf-Historie-Ansicht im Admin (die Daten liegen aber vor).

## Anti-Ziele

- Die Liste darf durch das Polling nicht flackern, Sortierung/Suche/Paging nicht verlieren und nicht
  die komplette Entity-Liste neu laden.
- Ein Fehler im Status-Tracking darf **nie** ein Backup abbrechen.
- Ein haengender Lock darf keine endlose Warteschleife erzeugen.

## Ansaetze

| Ansatz | Kurz | Abwaegung |
|---|---|---|
| A – Statusspalten am Repository | `backup_status`, `backup_step` an `muwa_backup_repository` | Wenigste Code, aber nur aktueller Stand, keine Historie |
| **B – Eigene Lauf-Tabelle (gewaehlt)** | eine Zeile pro Lauf | Mehr Code; dafuer Historie, Fehler je Lauf, spaetere Prozent-Spalte passt natuerlich |
| C – Nur im Cache | Status im App-Cache | Keine Migration, aber fluechtig (Cache-Clear) und unzuverlaessig bei mehreren Servern |

Gewaehlt: **B** (Entscheidung des Users). Aktualisierung per **Polling** eines schlanken
Status-Endpoints — der Shopware-Admin hat keinen Push-Kanal.

Verhalten bei belegtem Lock: **Einreihen** (Entscheidung des Users), nicht sofort ablehnen.

## Datenmodell: `muwa_backup_run`

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | BINARY(16) PK | |
| `backup_repository_id` | BINARY(16) FK → `muwa_backup_repository`, ON DELETE CASCADE | |
| `status` | VARCHAR(16) | `waiting`, `running`, `succeeded`, `failed`, `skipped`, `aborted` |
| `step` | VARCHAR(32) NULL | Schritt-Schluessel (siehe unten) |
| `step_current` / `step_total` | INT NULL | z. B. Pfad 2 von 5 |
| `source` | VARCHAR(16) | `manual`, `schedule`, `cli` |
| `error` | VARCHAR(1024) NULL | bereinigte Fehlermeldung (`ErrorSanitizer`, Passwort entfernt) |
| `queued_at`, `started_at`, `finished_at` | DATETIME(3) NULL | |
| `created_at`, `updated_at` | DATETIME(3) | |

Index auf (`backup_repository_id`, `created_at`) und auf `status`.

DAL-Definition `BackupRunDefinition` mit OneToMany vom Repository (read-only fuer die API:
Felder `WriteProtected(Context::SYSTEM_SCOPE)`). Geschrieben wird ausschliesslich per DBAL vom
Backend, damit Status-Updates billig sind und keine Entity-Events/Indexer ausloesen.

### Status-Uebergaenge

```
waiting ──► running ──► succeeded
   │           ├──────► failed
   │           └──────► aborted   (Worker verschwunden, siehe Haenger-Erkennung)
   └──────────────────► skipped   (Wartezeit ueberschritten / Repository geloescht / Plugin deaktiviert)
```

### Schritte (`step`)

In der Reihenfolge, in der `Backup::createBackup()` sie durchlaeuft:

| Schluessel | Wann | Anzeige (de) |
|---|---|---|
| `queued` | Lauf angelegt, wartet auf Worker | Wartet auf Worker |
| `waiting_for_lock` | anderes Backup oder S3-Upload haelt den Lock | Wartet auf laufenden Vorgang |
| `pre_command` | vor `BeforeBackupCreatedEvent` (nur wenn Pre-Command konfiguriert) | Pre-Backup-Befehl |
| `db_dump` | Datenbank-Dump erstellen | Datenbank-Dump |
| `db_upload` | Dump ins Repository schreiben | Dump sichern |
| `files` | Dateipfade, mit `step_current`/`step_total` | Dateien: Pfad 2/5 |
| `check` | `restic check` | Repository pruefen |
| `snapshots` | `saveSnapshots` + `collectAndSave` | Snapshots & Statistik |
| `post_command` | vor `AfterBackupCreatedEvent` (nur wenn Post-Command konfiguriert) | Post-Backup-Befehl |

Die Spiegelung nach S3 laeuft als eigene Nachricht und ist **nicht** Teil dieses Laufs.

## Ablauf im Backend

### Lauf anlegen (`waiting`)

- `BackupController::process` legt den Lauf an (`source = manual`, `status = waiting`,
  `step = queued`) und schickt die `runId` in der Nachricht mit:
  `CreateBackupMessage(repositoryId, runId)`.
- **Doppelstart**: Hat das Repository bereits einen Lauf in `waiting`/`running`, antwortet der
  Controller mit HTTP 409 und Fehlercode `MUWA_BACKUP_ALREADY_QUEUED`; die Detailseite zeigt eine
  Benachrichtigung. (Unsere Entscheidung, siehe unten.)
- `RunScheduledBackupHandler` legt seinen Lauf (`source = schedule`) beim Abholen an und gibt die
  `runId` an `BackupExecution` weiter.
- `muckiware:backup:create` (CLI) legt seinen Lauf (`source = cli`) direkt beim Start an.

### Ausfuehrung (`running` + Schritte)

- `BackupExecution::execute(string $repositoryId, ?string $runId = null)`: nach erfolgreichem
  Lock-Erwerb → `running`, `started_at`. Ohne `runId` wird ein Lauf angelegt (Rueckwaertskompatibilitaet).
- Neuer Service `BackupRunTracker` (Methoden etwa `create`, `start`, `step`, `finish`, `latestPerRepository`).
  Er kennt den aktiven Lauf des aktuellen Prozesses; `Backup`, `FilesRunner` und
  `BackupCommandSubscriber` melden nur `step(...)`. Wie der Tracker an den `FilesRunner`
  kommt (Factory-Argument, Setter), entscheidet der Builder.
- Jeder Tracker-Aufruf ist in `try/catch` gekapselt und loggt nur — **nie** Exception nach aussen.
- Ende: `succeeded` bzw. `failed` mit bereinigtem Fehler, `finished_at`.

### Einreihen bei belegtem Lock

- Wirft `BackupExecution` `BackupAlreadyRunningException`, faengt der Handler sie ab, setzt
  `step = waiting_for_lock` und schickt **dieselbe** Nachricht mit `DelayStamp` (30 s) erneut ab.
  Die Exception geht nicht mehr an den Messenger (keine Retries, kein failed transport).
- **Maximale Wartezeit**: 6 Stunden ab `queued_at`. Danach `skipped` mit Grund
  „Wartezeit ueberschritten“ und Admin-Benachrichtigung `backupFailed`.
- Vor jedem erneuten Versuch: Lauf nicht mehr `waiting` (z. B. als `aborted` markiert) oder
  Repository geloescht → Nachricht verwerfen.
- **Geplante Backups** verhalten sich wie bisher (`skipped` im Zeitplan, Lauf ebenfalls `skipped`).
  Begruendung: der naechste Termin kommt ohnehin, ein Stau aus Zeitplan-Laeufen waere unerwuenscht.
- **CLI** reiht nicht ein: belegter Lock → Fehlermeldung wie bisher, Lauf `skipped`.

### Fehlgeschlagene Laeufe

Ein Backup, das mit Exception endet, wird als `failed` gespeichert; der Handler schluckt die Exception
danach (Log + Admin-Benachrichtigung wie bisher). **Keine Messenger-Retries mehr** fuer fehlgeschlagene
Backups — heute laeuft ein kaputtes Backup bis zu dreimal neu und landet dann im failed transport.
Wer neu starten will, klickt erneut. (Unser Call.)

**Roter `restic check` zaehlt als `failed`** (Entscheidung des Users), auch wenn die Daten geschrieben
wurden. `Backup::getCheckFailure()` liefert den Grund; er landet bereinigt in `error`. Die restlichen
Schritte (Snapshots, Statistik, Post-Command) laufen wie bisher weiter, der Lauf wird erst am Ende
`failed` gesetzt. Die Admin-Benachrichtigung ist dann `backupFailed` statt `backupSucceeded`.

### Haenger-Erkennung (`aborted`)

restic laeuft blockierend; waehrend eines langen Pfads gibt es keine Zwischen-Updates. Ein reiner
Heartbeat-Zeitstempel wuerde lange, gesunde Laeufe faelschlich als tot melden. Stattdessen:

- `BackupExecution` haelt waehrend des Laufs **zusaetzlich** einen eigenen Lock
  `mucki-facility.backup.run.<runId>`. Grund: `mucki-facility.backup` wird auch von
  `muckiware:repository:upload` gehalten (stundenlang moeglich) und taugt daher nicht als
  Lebenszeichen eines bestimmten Laufs.
- Ein Lauf in `running`, dessen Lauf-Lock frei ist (`acquire(false)` und sofort `release()`),
  laeuft nicht mehr → `aborted`.
- **Selbstheilung an drei Stellen** (gemeinsame Methode im Tracker): Status-Endpoint, Controller vor der
  409-Pruefung und Handler. Dabei auch: `waiting` aelter als 6 h → `skipped` („Wartezeit ueberschritten“).
  So blockiert ein verwaister Lauf (kein Worker, Nachricht im failed transport, Queue geleert) nie
  dauerhaft neue Starts.
- Kurzzeitiges Halten des Locks durch den Endpoint ist unkritisch: ein gleichzeitig startender
  Worker reiht sich dadurch nur einmal mehr ein.
- **Voraussetzung**: Web-Prozess und Worker teilen denselben Lock-Store (`LOCK_DSN`, z. B. flock auf
  demselben Host oder Redis/DB). Beim Umsetzen pruefen und in der README dokumentieren.

### Aufraeumen

Beim Anlegen eines Laufs werden pro Repository alle Laeufe ausser den letzten 50 abgeschlossenen
geloescht. (Unsere Entscheidung.)

## Status-Endpoint

`GET /api/_action/muwa/backup/runs/status`, ACL `muwa_backup_repository:read`.

Antwort: pro Repository der aktive Lauf (`waiting`/`running`) oder, wenn keiner aktiv ist, der letzte
abgeschlossene Lauf:

```json
{ "runs": { "<repositoryId>": {
    "id": "...", "status": "running", "step": "files",
    "stepCurrent": 2, "stepTotal": 5,
    "queuedAt": "...", "startedAt": "...", "finishedAt": null, "error": null } } }
```

Eine SQL-Abfrage (neuester Lauf je Repository), keine Assoziationen; `error` nur gekuerzt.

## Admin: Repository-Liste

- Neue Spalte **Backup-Status** in `muwa-backup-repository-list` (nach „Aktiv“), nicht sortierbar.
- Zeile 1: `sw-label` als Pill — `waiting` (neutral), `running` (info/blau), `succeeded` (success),
  `failed`/`aborted` (danger), `skipped` (warning). Bei abgeschlossenen Laeufen dahinter Datum/Uhrzeit.
- Zeile 2: Schritt-Text aus Snippets (`muwa-backup-repository.run.step.<key>`), bei `files` mit
  „Pfad X/Y“; bei `failed` der gekuerzte Fehler als `title`-Tooltip.
- Kein Lauf vorhanden → „–“.
- **Polling**: nur die Status-Map wird abgefragt und in ein reaktives Objekt gemischt, nicht die
  Entity-Liste. Intervall 5 s solange ein Lauf aktiv ist, sonst 30 s. Start in `created`/beim
  Tab-Wechsel auf `repositoryList`, Stopp in `beforeUnmount` und wenn der Tab gewechselt wird.
  Pausieren, wenn `document.hidden`.
- Wechselt ein Lauf von aktiv auf abgeschlossen, wird die Liste einmal neu geladen, damit
  „Letzter Check“ und „Letztes Backup“ aktuell sind.
- Snippets in de-DE und en-GB. Achtung: `$tc(key, 0, {...})` verliert in 6.7 benannte Werte —
  `$t(key, {...})` verwenden.

## Rahmenbedingungen (aus Plugin-CLAUDE.md)

- Muss auf Shopware 6.6 **und** 6.7 laufen (DBAL 3/4, Vue-Compat, `mt-*`-Komponenten).
  Fuer die Pill `sw-label` nutzen, kein 6.7-only-Component.
- Kein Autowiring: neue Services, Controller und Handler in `services.xml` eintragen
  (Handler zusaetzlich mit `messenger.message_handler`-Tag wie die bestehenden).
- `MuckiFacilityPlugin::dropPluginTables()` um `muwa_backup_run` erweitern (vor
  `muwa_backup_repository` wegen FK).
- Messages tragen nie Passwoerter: `CreateBackupMessage` bekommt nur zusaetzlich die `runId`.
- Neue Route in `RouteAclTest` beruecksichtigen; neue Rolle nicht noetig, `muwa_backup_repository.viewer`
  bekommt `muwa_backup_run:read`.
- Plugin-Stil beibehalten (kein `final`/`readonly`-Umbau; LightsOn-Standards gelten hier nicht).
- Version und `CHANGELOG.md` mitziehen.

## Tests

- Unit: `BackupRunTracker` (Uebergaenge, Fehler werden geschluckt), `CreateBackupHandler`
  (Einreihen mit `DelayStamp`, Abbruch nach Maximalwartezeit, verworfene Nachricht),
  Controller 409 bei Doppelstart, Endpoint-Antwort inkl. `aborted`-Erkennung.
- PHPUnit 11 (Data-Provider `static`), Ausfuehrung nur per `ddev exec`; vorher `ddev mutagen sync`.
- Admin manuell im Browser: zwei Repos nacheinander starten → eines `running`, eines `waiting`;
  Schritte wandern; Worker waehrend eines Laufs killen → `aborted`.

## Entscheidungen

Vom User:
- Ansatz B (eigene Tabelle), Polling, keine Library-Aenderung, Anzeige in der Repository-Liste.
- Manuelle Starts bei belegtem Lock werden eingereiht.
- Roter `restic check` am Ende eines Laufs → Lauf `failed`.

Unsere Calls (zum Pruefen):
- Doppelstart desselben Repositories wird mit 409 abgelehnt.
- Einreihen nur fuer manuelle Starts; Zeitplan und CLI verhalten sich wie bisher (`skipped`).
- Wiederholungsintervall 30 s, Maximalwartezeit 6 h.
- Haenger-Erkennung ueber einen eigenen Lock pro Lauf statt Heartbeat; Selbstheilung in Endpoint, Controller, Handler.
- Fehlgeschlagene Backups werden nicht mehr vom Messenger wiederholt.
- Ein manuelles Backup wartet auch auf einen laufenden S3-Upload (gleicher Lock); Anzeige „Wartet auf laufenden Vorgang“.
- Abgeschlossener letzter Lauf wird angezeigt, wenn keiner aktiv ist.
- Aufbewahrung: letzte 50 Laeufe je Repository.
- Polling 5 s aktiv / 30 s idle.

## Dem Builder ueberlassen

- Wie der Tracker in `FilesRunner` gelangt; genaue Klassen-/Methodennamen.
- Ob die Lauf-Anlage im Controller in einer Transaktion mit dem Doppelstart-Check passiert
  (Race zweier gleichzeitiger Klicks).
- Migrations-Timestamp, Snippet-Texte im Detail.
