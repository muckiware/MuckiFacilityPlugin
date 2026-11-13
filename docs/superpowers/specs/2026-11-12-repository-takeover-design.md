# Uebernahme bestehender restic-Repositories

Design-Dokument, 12.11.2026. Plugin `muckiware/facility-plugin`.

## Warum

Wer restic bisher von Hand oder per Batch/Cron auf der Kommandozeile betrieben hat, soll auf
MuckiSafe umsteigen koennen, ohne die bis dahin erstellten Backups zu verlieren. Heute kann das
Plugin nur Repositories verwalten, die es selbst per `restic init` angelegt hat. Kuenftig laesst
sich ein vorhandenes lokales Repository in die Plugin-Verwaltung uebernehmen: Es bleibt an seinem
Ort, wird nicht kopiert und nicht neu initialisiert, sondern nur bekannt gemacht.

## Ablauf aus Nutzersicht

1. In der Repository-Liste gibt es neben "Repository anlegen" einen zweiten Button
   **"Bestehendes Repository uebernehmen"**.
2. Er fuehrt auf eine neue, eigene Seite mit drei Feldern: **Name**, **lokaler Pfad des restic
   Repositories** (mit dem vorhandenen Ordner-Browser) und **Passwort**. Dazu ein Hinweistext
   (siehe unten) und der Button **"Weiter"**.
3. Klick auf "Weiter": Der Zugriff wird geprueft, die Snapshots werden uebernommen, das
   Repository wird in der Verwaltung angelegt.
4. Bei Erfolg springt die Administration in die Detail-Seite des neuen Repositories, Tab
   **Konfiguration**. Dort steht ein Hinweis mit der Anzahl der uebernommenen Snapshots und der
   Bitte, die Konfiguration zu ergaenzen.
5. Bei einem Fehler bleibt man auf der Uebernahme-Seite und sieht die Meldung. Gespeichert wird
   dann nichts.
6. Im Hintergrund laufen `restic check` und die Statistik. Sobald sie fertig sind, fuellt sich
   der Status-Tab, und es gibt eine Admin-Benachrichtigung.
7. Im Tab Konfiguration zeigt die Karte "Backup-Pfade" eine **Vorschlagsliste** mit den Pfaden
   aus den uebernommenen Snapshots. Per Klick werden Vorschlaege zu Backup-Pfaden; gespeichert
   wird erst mit dem normalen Speichern der Konfiguration.

## Ziele

- Neue Seite `muwa-backup-repository-takeover` im Modul `muwa-backup-repository`, eigene Route,
  erreichbar ueber einen zweiten Button in der Repository-Liste (nur im Tab `repositoryList`).
- Neue API-Route, die ein bestehendes lokales restic-Repository prueft und uebernimmt.
- Die vorhandenen Snapshots stehen danach in `muwa_backup_repository_snapshots` und lassen sich
  wie alle anderen wiederherstellen und loeschen.
- Check- und Statistik-Eintrag (`muwa_backup_repository_checks`, `muwa_backup_repository_stats`)
  entstehen nachgelagert ueber die Message Queue.
- Vorschlagsliste fuer Backup-Pfade im Tab Konfiguration.
- Das uebernommene Repository ist danach ein ganz normales Plugin-Repository.

## Nicht-Ziele

- Keine Remote-Backends (S3, SFTP, ...). Nur lokale Pfade.
- Kein CLI-Befehl fuer die Uebernahme.
- Kein automatisches Eintragen von Backup-Pfaden. Die Pfade aus den Snapshots sind nur
  Vorschlaege zum Anklicken.
- Keine Vorschlaege fuer Restore-Pfad, Dump-Pfad, Aufbewahrung oder Datenbank-Backup-Typ.
- Kein Passwort aus Umgebungsvariable oder Datei. Die Uebernahme speichert wie das Anlegen
  verschluesselt (`PasswordSource::ENCRYPTED`).
- Kein Schutz der uebernommenen Snapshots vor den Forget-Regeln (siehe Entscheidungen).
- Kein Abschalten des alten Batch-/Cronjobs durch das Plugin. Die Seite weist nur darauf hin.
- Kein Aufraeumen der in `CLAUDE.md` unter "Auffaelligkeiten" gelisteten Altlasten.

## Anti-Ziele (was das Feature zum Fehlschlag macht, auch wenn es "funktioniert")

- Die Uebernahme veraendert das restic-Repository: `init`, `unlock`, `prune`, `forget` oder ein
  Backup laufen im Zuge der Uebernahme. Sie darf nur lesen. (`restic check` im Hintergrundlauf
  ist lesend und erlaubt.)
- Ein Datensatz entsteht, obwohl Pfad oder Passwort nicht passen — oder ein Datensatz ohne
  Snapshots entsteht, weil der Import mittendrin abgebrochen ist.
- Ein einzelner "ungewoehnlicher" Snapshot (alte restic-Version, viele Pfade, fremder Host) laesst
  die ganze Uebernahme scheitern.
- Das Repository-Passwort taucht in Logs, Exception-Meldungen, API-Antworten, Messages oder
  Prozess-Argumenten auf.
- Die Seite laeuft bei einem grossen Repository in ein Webserver-Zeitlimit, obwohl nichts kaputt
  ist.
- Ein uebernommenes Repository startet von selbst Backups oder Forget-Laeufe, bevor der Nutzer
  die Konfiguration ergaenzt hat.

## Entscheidungen

| Thema | Entscheidung | Quelle |
|---|---|---|
| Zweck | Umstieg von handbetriebenem restic auf MuckiSafe ohne Verlust der bisherigen Backups | Nutzer |
| Ort der Bedienung | Neue, separate Seite (nicht in die Create-Seite integriert) | Nutzer |
| Eingaben | Lokaler Pfad und Passwort, dann "Weiter" | Nutzer |
| Nach der Uebernahme | Sprung in den Tab Konfiguration der Detail-Seite | Nutzer |
| Uebrige Konfiguration | Bleibt leer | Nutzer |
| Forget-Regeln | Uebernommene Snapshots fallen ganz normal unter die Forget-Regeln des Plugins, sobald welche konfiguriert sind | Nutzer |
| Backup-Pfade aus Snapshots | Vorschlagsliste zum Anklicken im Tab Konfiguration, nicht automatisch eingetragen | Nutzer |
| Einstieg | Zweiter Button in der Repository-Liste | Annahme (bestaetigt) |
| Feld "Name" | Wird auf der Uebernahme-Seite abgefragt, Pflichtfeld | Annahme (bestaetigt) |
| Aktiv-Status | Repository ist nach der Uebernahme inaktiv | Annahme (bestaetigt) |
| Passwort | Verschluesselt gespeichert wie bei neu angelegten Repositories | Annahme (bestaetigt) |
| Snapshots ohne Groessenangabe | Werden uebernommen, nur ohne Groesse | Annahme (bestaetigt) |
| Fehlgeschlagene Pruefung | Nichts wird gespeichert | Annahme (bestaetigt) |
| Doppelter Pfad | Ein Pfad, der schon als Repository in der Verwaltung steht, wird abgelehnt | Annahme (bestaetigt) |
| Backends | Nur lokale Pfade | Annahme (bestaetigt) |
| Sofort / nachgelagert | Im Klick: `restic snapshots` (Zugriffspruefung + Import) und Datensatz. Ueber die Message Queue: `restic check` + Statistik | Vorschlag (bestaetigt) |
| Leerer Status-Tab | In Kauf genommen: direkt nach der Uebernahme und ohne Messenger-Worker bleibt der Status-Tab leer, bis der Lauf durch ist bzw. `muckiware:backup:check` + `muckiware:repository:stats` von Hand laufen | Vorschlag (bestaetigt) |
| Rechte | Seite und Uebernahme-Route verlangen `muwa_backup_repository:create` (Rolle `creator`) | Vorschlag (nicht widersprochen) |
| Datensatz-Defaults | Typ `noneDatabase`, Hostname leer, Forget-Werte 0, keine Datenbankverbindung, kein Zeitplan, keine Backup-Pfade, Restore-/Dump-Pfad leer, Pre-/Post-Command `none` | Vorschlag (nicht widersprochen) |
| Hintergrundlauf scheitert | Wird geloggt; Repository bleibt uebernommen, Status-Tab bleibt leer | Vorschlag (nicht widersprochen) |
| Benachrichtigung | Admin-Benachrichtigung, wenn der Hintergrundlauf fertig ist | Vorschlag (nicht widersprochen) |
| Hinweistext | Die Seite erinnert daran, den alten Batch-/Cronjob abzuschalten | Vorschlag (nicht widersprochen) |
| Lange Pfadlisten | Spalte `muwa_backup_repository_snapshots.paths` wird per Migration verbreitert (heute `varchar(155)`) | Claudes Entscheidung |
| Vorschlaege: Quelle | Live aus `restic snapshots`, nicht aus der gespiegelten Spalte `paths` | Claudes Entscheidung |
| Vorschlaege: Dateien | Auch einzelne Dateien sind als Vorschlag waehlbar, markiert als "Datei" | Claudes Entscheidung |
| Vorschlaege: Sichtbarkeit | Bei jedem Repository mit Snapshots, nicht nur bei uebernommenen | Claudes Entscheidung |
| Kennzeichnung "uebernommen" | Kein neues Feld am Repository | Claudes Entscheidung |
| Version | Neuer Abschnitt `0.10.0` in `CHANGELOG.md` und `composer.json` | Claudes Entscheidung |

## Befunde im Bestand, die das Design praegen

Am 12.11.2026 im Code geprueft:

1. **Anlegen ist zweistufig und laesst sich spiegeln.** `InitBackupRepositoryController` prueft
   Eingaben, bringt das Passwort per `BackupRepository::prepareStoredPassword()` in Speicherform,
   ruft `restic init` auf und schreibt erst danach per `createBackupRepository()` im System-Scope.
   Die Uebernahme folgt derselben Reihenfolge, ersetzt `restic init` aber durch `restic snapshots`.
2. **`BackupFileSnapshotsRepository::createNewSnapshots()` bricht bei aelteren Snapshots.** Es
   greift ungeprueft auf `$snapshot['summary']['total_bytes_processed']` und
   `['total_files_processed']` zu. `summary` schreibt restic erst seit 0.17 in den Snapshot;
   aeltere Snapshots haben den Schluessel nicht. Ebenso ungeprueft: `hostname`, `paths`.
3. **Spalte `paths` ist `varchar(155)`** (`Migration1735826311`), befuellt mit
   `implode(',', $snapshot['paths'])`. Ein fremder Snapshot mit mehreren langen Pfaden sprengt
   das; der DAL-Write wuerde fuer alle Snapshots fehlschlagen. Die Definition nutzt `StringField`.
4. **`Helper::createDateTimeFromString()`** kuerzt Nanosekunden nur, wenn danach `+` oder `-`
   folgt. Zeitstempel mit `Z` (Snapshots von UTC-Hosts, z. B. `2024-03-01T02:00:00.123456789Z`)
   liefern damit `null` und der Snapshot bekaeme kein Datum.
5. **`ManageRepository::getSnapshots()`** arbeitet nur mit einer Repository-ID (laedt den
   Datensatz), schluckt Exceptions und gibt dann `''` zurueck. Fuer die Zugriffspruefung vor dem
   Anlegen ist es so nicht nutzbar: es gibt noch keinen Datensatz, und der Fehler muss sichtbar
   werden.
6. **Check-Eintrag** entsteht heute nur in `Services\Backup::createCheckItem()` (ruft
   `checkBackup()` ueber den `FilesRunner`), die Statistik in
   `Services\RepositoryStats::collectAndSave()`. `collectAndSave()` liest den **letzten
   gespeicherten** Check-Status — der Check muss also vor der Statistik laufen.
7. **Backup-Pfade** gehen unveraendert an restic (`FilesRunner`), restic akzeptiert Ordner und
   Dateien. Der Ordner-Browser (`DirectoryBrowser::listDirectories()`) listet nur Ordner.
8. **Leere Konfiguration ist abgesichert**: Ein Backup ohne Pfade laeuft seit dem Bugfix in
   `Services\Backup::createBackup()` nicht mehr in den `\Error` (siehe `CLAUDE.md`, "Falle:
   backupPaths").
9. **`BackupRepositorySettings`** hat typisierte Properties ohne Defaults; ein Getter auf ein
   nicht gesetztes Feld ist ein `\Error`. Wer das DTO fuer die Uebernahme baut, muss alle Felder
   belegen, die `BackupRepository::createPayload()` liest.
10. **`RouteAclTest`** verlangt fuer jede API-Route einen Eintrag mit ACL-Recht.

## Backend

### Uebernahme-Route

`POST /api/_action/muwa/backup/repository/takeover`, ACL `muwa_backup_repository:create`, in einem
eigenen Controller (`TakeoverBackupRepositoryController`), analog zu
`InitBackupRepositoryController`.

Eingabe: `id` (von der Administration erzeugte UUID, wie beim Anlegen), `name`, `repositoryPath`,
`repositoryPassword`. Keine Passwort-Wiederholung — das Passwort wird gegen das Repository
geprueft, ein Tippfehler faellt dort auf.

Reihenfolge (Absicht, analog zum Anlegen):

1. **Eingaben pruefen.** `id` gueltige UUID, `name` nicht leer, `repositoryPath` nicht leer,
   `repositoryPassword` nicht leer. Pfad-Zeichenpruefung wie `DirectoryBrowser` (Laenge,
   Steuerzeichen, keine Segmente `.`/`..`). Relative Pfade gelten relativ zum Projektverzeichnis
   und werden normalisiert gespeichert, wie `DirectoryBrowser::normalize()` es tut.
2. **Pfad pruefen.** Das Verzeichnis existiert und ist lesbar. Sieht es nicht nach einem
   restic-Repository aus (keine Datei `config`), gibt es eine eigene, verstaendliche Meldung,
   statt die restic-Fehlermeldung durchzureichen.
3. **Dublette pruefen.** Existiert schon ein Datensatz mit demselben normalisierten
   `repositoryPath`, wird abgelehnt und der Name des vorhandenen Repositories genannt.
4. **Passwort in Speicherform bringen** (`prepareStoredPassword()`, `ENCRYPTED`). Fehlt
   `MUWA_FACILITY_SECRET`, scheitert die Uebernahme hier, bevor irgendetwas passiert.
5. **`restic snapshots` ausfuehren** (JSON) mit dem eingegebenen Pfad und Passwort. Das ist
   zugleich die Zugriffspruefung. Schlaegt der Aufruf fehl (falsches Passwort, gesperrtes oder
   defektes Repository), geht die Meldung an die Administration und es wird nichts gespeichert.
   Liefert er kein gueltiges JSON-Array, gilt das ebenfalls als Fehler.
6. **Datensatz schreiben** per `BackupRepository::createBackupRepository()` mit den Defaults aus
   der Tabelle oben (`active = false`, `type = noneDatabase`, ...).
7. **Snapshots importieren** aus dem Ergebnis von Schritt 5 — ohne zweiten restic-Aufruf.
8. **Hintergrundlauf anstossen** (Message, siehe unten).
9. Antwort: `success`, `id`, `snapshotsCount`.

Schritt 6 und 7 duerfen keinen halben Zustand hinterlassen: Scheitert der Snapshot-Import, wird
der gerade angelegte Datensatz wieder entfernt (Transaktion oder Kompensation — Wahl des
Builders) und der Fehler gemeldet. Ein Repository ohne Snapshots (leeres Array) ist dagegen ein
gueltiger Erfolg.

Fehlerantworten folgen dem Muster der `filesystem`-/`database-connection`-Routes: strukturierter
Fehler mit eigenem Code (`MUWA_TAKEOVER_*`), den die Administration in eine uebersetzte Meldung
umsetzt; die restic-Ausgabe haengt als Detail an. Das Passwort darf in keiner Meldung stehen.

Fuer Schritt 5 braucht `ManageRepository` (oder ein neuer kleiner Service) einen Weg,
`restic snapshots` mit explizitem Pfad und Passwort auszufuehren, der Exceptions **nicht**
schluckt. restic-Aufrufe laufen weiter ausschliesslich ueber `muckiware/restic`
(`MuckiRestic\Library\Manage`), eigener Binary-Pfad aus `Settings` wie ueberall.

### Robuster Snapshot-Import

`BackupFileSnapshotsRepository::createNewSnapshots()` wird fuer fremde Snapshots tolerant. Das
gilt fuer alle Aufrufer, also auch fuer `saveSnapshots()` nach jedem Backup — sonst waere das
uebernommene Repository nach dem ersten eigenen Backup wieder kaputt.

- `summary` fehlt oder ist unvollstaendig: `size` bleibt `null`, `totalFilesProcessed` `null`
  (bzw. 0, falls die Spalte das verlangt). Die Administration zeigt dafuer den vorhandenen
  Platzhalter fuer "kein Wert".
- `hostname`, `paths`, `short_id` fehlen: leerer Wert bzw. aus `id` abgeleitet, kein Abbruch.
- Zeitstempel mit `Z` und mit Nanosekunden werden korrekt gelesen
  (`Helper::createDateTimeFromString()`).
- Ein einzelner nicht lesbarer Snapshot (kein `id`) wird uebersprungen und geloggt, die anderen
  werden importiert.
- `paths`: neue Migration macht aus `varchar(155)` ein `TEXT`, die Definition wird passend
  umgestellt (`LongTextField`). Migration idempotent und in 6.6/6.7 lauffaehig (siehe
  `CLAUDE.md`, Regeln fuer Migrationen).

### Hintergrundlauf: Check und Statistik

Neue Message (traegt nur die `backupRepositoryId`, kein Passwort — Muster
`UpdateRepositoryStatsMessage`) plus Handler. Der Handler:

1. laedt das Repository frisch (Passwort wird dabei aufgeloest),
2. fuehrt `restic check` aus und speichert das Ergebnis als Check-Eintrag
   (`BackupRepositoryChecks::saveNewCheck()`),
3. ruft `RepositoryStats::collectAndSave()` auf,
4. schreibt eine Admin-Benachrichtigung (`Services\AdminNotification`) mit Repository-Name und
   Check-Status.

Fehler in Schritt 2 oder 3 werden geloggt und brechen den Handler nicht mit einer Exception ab
(kein Retry-Sturm); die Benachrichtigung nennt dann den Fehlschlag. Wie der Check technisch
angestossen wird (Wiederverwendung von `Services\Backup::createCheckItem()` oder ein schlankerer
Weg), entscheidet der Builder — Bedingung: kein `unlock`/`prune` als Nebenwirkung.

Handler-Registrierung wie die bestehenden (`services.xml`, kein Autowiring).

### Pfad-Vorschlaege

`POST /api/_action/muwa/backup/repository/path-suggestions`, ACL `muwa_backup_repository:update`,
Eingabe `id`.

- Liest die Snapshots live per `restic snapshots` (nicht aus der DB-Spalte: die ist
  komma-verkettet und damit bei Pfaden mit Komma mehrdeutig).
- Sammelt die Pfade ohne Dubletten, sortiert nach dem juengsten Snapshot, in dem sie vorkommen.
- Liefert je Pfad: `path`, `lastSeenAt`, `hostname` (des juengsten Snapshots), `snapshotCount`,
  `state` (`directory` / `file` / `missing`, geprueft auf diesem Server ohne Symlink-Aufloesung).
- Pfade, die schon als Backup-Pfad des Repositories eingetragen sind, werden als `configured`
  markiert.
- Liste gedeckelt (Vorschlag: 200 Eintraege, `truncated`-Flag wie beim Ordner-Browser).
- Fehler beim restic-Aufruf: leere Liste plus Fehlerhinweis, kein 500.

Die Route gibt nur Pfadnamen und deren Existenz zurueck, nie Dateiinhalte.

## Administration

Alles im Modul `muwa-backup-repository`, im vorhandenen Stil (`Component.register`, Twig-Templates,
`sw-*`-Komponenten, Feature-Flag-Zweige `isV6600`/`isV6700` nach `CLAUDE.md`). Muss in Shopware
6.6 **und** 6.7 funktionieren.

### Liste

Zweiter Button im Smart-Bar-Bereich, nur im Tab `repositoryList`, links vom primaeren
"Repository anlegen", nicht primaer. Deaktiviert ohne Rolle `muwa_backup_repository.creator`.

### Uebernahme-Seite

Neue Route `takeover` (Pfad `takeover`, `parentPath: 'muwa.backup.repository.index'`, Privileg
`muwa_backup_repository.creator`), Komponente `muwa-backup-repository-takeover`.

- Eine Karte mit: Name (`sw-text-field`, Pflicht), Repository-Pfad
  (`muwa-backup-repository-path-field`, Pflicht), Passwort (`sw-password-field`, Pflicht).
- Erklaertext ueber den Feldern: was die Uebernahme tut (Repository bleibt, wo es ist; nur
  Lesen; Snapshots werden uebernommen), dass die Konfiguration danach zu ergaenzen ist, und der
  Hinweis, den bisherigen Batch-/Cronjob abzuschalten, damit nicht zwei Stellen in dasselbe
  Repository sichern und aufraeumen. Ausserdem der Hinweis, dass kuenftige Forget-Regeln auch
  die uebernommenen Snapshots betreffen.
- Smart-Bar: "Abbrechen" (zurueck zur Liste) und "Weiter" (`sw-button-process`, Ladezustand
  waehrend des Aufrufs).
- Erfolg: `router.push` auf `muwa.backup.repository.detail` mit `id` und Tab
  `backupRepositoryConfig`, plus Erfolgs-Notification mit der Snapshot-Anzahl.
- Fehler: Fehler-Notification mit der uebersetzten Meldung, Felder bleiben gefuellt (das
  Passwort darf bleiben, solange man auf der Seite ist).

### Detail-Seite, Tab Konfiguration

In der Karte "Backup-Pfade" erscheint ueber der Pfad-Tabelle ein Bereich **"Pfade aus
vorhandenen Snapshots"**, sobald die Vorschlags-Route mindestens einen Pfad liefert, der noch
nicht konfiguriert ist:

- je Vorschlag: Pfad, Vermerk "vorhanden" / "Datei" / "nicht gefunden", zuletzt gesichert am,
  Host;
- Klick auf "Uebernehmen" fuegt den Pfad der Backup-Pfad-Liste hinzu (wie ein von Hand
  angelegter Eintrag: `compress = false`, naechste `position`). Gespeichert wird erst mit dem
  normalen Speichern der Konfiguration;
- "nicht gefunden" ist waehlbar, aber sichtbar gewarnt;
- uebernommene Vorschlaege verschwinden aus der Liste.

Geladen wird nur fuer die Rolle `editor`, beim Oeffnen des Tabs, nicht blockierend (Ladeanzeige
im Bereich; die Seite ist ohne die Vorschlaege voll bedienbar).

Der Hinweis nach der Uebernahme ("x Snapshots uebernommen, bitte Konfiguration ergaenzen") ist
eine Notification beim Sprung, kein dauerhafter Zustand am Repository.

### Snippets

Alle neuen Texte in `snippet/de-DE.json` und `snippet/en-GB.json`.

## Tests

Unit-Tests ueber `phpunit.unit.xml` (der Shopware-Bootstrap laeuft hier nicht, siehe `CLAUDE.md`):

- Controller: jede Ablehnung (ungueltige ID, leerer Name/Pfad/Passwort, Pfad kein
  restic-Repository, Dublette, Passwort nicht ablegbar, `restic snapshots` schlaegt fehl) fuehrt
  dazu, dass **kein** Datensatz geschrieben wird; Erfolgsfall schreibt Datensatz, importiert
  Snapshots, dispatcht die Message.
- Der Erfolgs-Payload hat `active = false` und die leeren Defaults.
- Snapshot-Import: Snapshot ohne `summary`, mit Zeitstempel `...Z` und Nanosekunden, mit sehr
  langer Pfadliste, ohne `hostname`; ein defekter Snapshot zwischen gueltigen.
- Handler: Reihenfolge Check vor Statistik, Fehler werden geschluckt und geloggt,
  Benachrichtigung in beiden Faellen.
- Pfad-Vorschlaege: Dubletten, Sortierung, `state`, `configured`, Deckelung.
- `RouteAclTest`: beide neuen Routen eingetragen.
- Administration: Tests im Stil der vorhandenen `tests/Administration/*AdministrationTest.php`.
- Kein Passwort in Log-Aufrufen (Test wie bei den bestehenden Passwort-Resolvern, soweit dort
  vorhanden).

Manuell im Browser (6.7, DDEV): Uebernahme eines per CLI angelegten Repositories mit mehreren
Snapshots, falsches Passwort, Pfad ohne Repository, doppelte Uebernahme, Sprung in die
Konfiguration, Vorschlagsliste, Status-Tab nach Worker-Lauf.

## Dokumentation

- `README.md`: Abschnitt zur Uebernahme inkl. Hinweis auf alten Cronjob und Forget-Regeln.
- `CHANGELOG.md`: Abschnitt `0.10.0`.
- `CLAUDE.md` des Plugins: neue Routen, Message/Handler, Migration, Seite.

## Dem Builder ueberlassen

- Klassen- und Dateinamen ausserhalb der oben genannten, Aufteilung in Services.
- Transaktion oder Kompensation fuer Datensatz + Snapshot-Import.
- Technischer Weg fuer `restic check` im Handler.
- Genaue Fehlercodes und Texte.
- Layout der Vorschlagsliste innerhalb der Karte.
- Ob `totalFilesProcessed` bei fehlendem `summary` `null` oder 0 wird (abhaengig von der Spalte).
