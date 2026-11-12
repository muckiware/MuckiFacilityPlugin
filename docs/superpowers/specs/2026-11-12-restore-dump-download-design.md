# Download wiederhergestellter Datenbank-Dumps im Tab Snapshots

Design-Dokument, 12.11.2026. Plugin `muckiware/facility-plugin`.

## Warum

Tritt beim Kunden ein Fehler auf, soll der Entwickler den Datenstand des Kunden schnell lokal
nachstellen koennen, um parallel zu debuggen. Heute heisst das: per Shell auf den Server, den
wiederhergestellten Dump im Restore-Verzeichnis suchen und per `scp` holen. Kuenftig soll der
Dump direkt aus der Administration heruntergeladen werden koennen.

## Ablauf aus Nutzersicht

1. Im Tab **Snapshots** einen Snapshot wie bisher per "Wiederherstellen" in den Restore Path
   zuruecksichern (unveraendertes Verhalten).
2. Im neuen Panel unter der Snapshot-Liste erscheint der Inhalt des Restore Path als
   aufklappbarer Ordnerbaum.
3. Klick auf eine Dump-Datei startet den Download im Browser.

## Ziele

- Neues Panel (eigene `sw-card`) im Tab `backupRepositorySnapshots` der Detail-Seite, **unter**
  der bestehenden Snapshot-Liste (`sw-entity-listing`).
- Das Panel bildet das Restore-Verzeichnis des Repositories (`restorePath`) **1:1** als
  Ordnerbaum ab — so wie die Dateien auf der Platte liegen.
- Angezeigt wird nur, was real im Restore-Verzeichnis liegt, also was ein Nutzer vorher selbst
  wiederhergestellt hat. **Keine** Anzeige von Snapshot-Inhalten direkt aus dem restic-Repository.
- Download zunaechst **nur fuer Datenbank-Dumps**.
- Download und Anzeige nur fuer Nutzer mit dem bestehenden Restore-Recht
  (`muwa_backup_repository:restore`, Rolle `muwa_backup_repository.restore`).

## Nicht-Ziele

- Keine Aenderung am Restore selbst. restic stellt weiterhin per `--target=<restorePath>` mit dem
  vollstaendigen Originalpfad darunter wieder her; es gibt **keine** Unterordner pro Snapshot.
  (Bewusst so entschieden — die Alternative "Ordner pro Snapshot" wurde verworfen.)
- Keine Zuordnung von Dateien zu Snapshots im Baum. Mehrere Restores landen im selben Baum und sind
  nur ueber den Zeitstempel im Dateinamen (`<datum>_<dbname>.backup.sql…`) unterscheidbar.
- Kein Download von Projektdateien (Files-Backups) — sie erscheinen im Baum, sind aber nicht
  klickbar.
- Kein Loeschen, Umbenennen oder Aufraeumen ueber das Panel. Nur ansehen und herunterladen.
- Kein neues ACL-Recht.
- Keine restic-Aufrufe fuer dieses Feature (kein `restic ls`/`restic dump`) — es wird nur das
  lokale Dateisystem gelesen.
- Keine Anonymisierung der Dumps.

## Anti-Ziele (was das Feature zum Fehlschlag macht, auch wenn es "funktioniert")

- Ein Pfad ausserhalb des Restore Path ist ueber die API lesbar oder herunterladbar
  (Path Traversal, Symlinks, absolute Pfade). Das waere ein Datei-Leak des ganzen Servers.
- Ein Nutzer ohne Restore-Recht kann den Baum sehen oder eine Datei laden — auch nicht durch
  direkten API-Aufruf.
- Ein mehrere GB grosser Dump wird in den PHP-Speicher geladen statt gestreamt.
- Das Oeffnen des Tabs blockiert, weil ein grosser Restore-Baum komplett vorab gescannt wird.

## Entscheidungen

| Thema | Entscheidung | Quelle |
|---|---|---|
| Ort | Neues Panel im Tab Snapshots, unter der bestehenden Liste | Nutzer |
| Darstellung | Aufklappbarer Ordnerbaum | Nutzer |
| Inhalt | Restore-Verzeichnis 1:1, nur real wiederhergestellte Dateien | Nutzer |
| Restore-Struktur | Unveraendert (kein Ordner pro Snapshot) | Nutzer |
| Download-Umfang | Nur Datenbank-Dumps | Nutzer |
| Berechtigung | Bestehendes Restore-Recht | Nutzer |
| Als Dump gilt | Dateinamen, die auf `.backup.sql.tar.gz` oder `.backup.sql` enden | Annahme (bestaetigt) |
| Zu weiter Restore Path | Panel und beide Routen verweigern den Dienst ("Restore Path nicht zulaessig"), wenn der Restore Path zu weit gefasst ist. Regeln wie bei `Settings::getOwnDumpPathRejection()`. Restore und Configuration-Formular bleiben unveraendert | Nutzer |
| Alt-Dumps | Zusaetzlich `.backup.sql.gz` (aeltere Plugin-Versionen, `prepareDbBackupFileName()` erzeugt das Suffix noch) — sonst waeren Restores alter Snapshots nicht ladbar | Claudes Entscheidung |
| Relativer `restorePath` | Wird relativ zum Projektverzeichnis aufgeloest (wie Dump-Pfad und `DirectoryBrowser::normalize()`). restic bekommt den Wert heute unveraendert und loest gegen das Arbeitsverzeichnis des Workers/CLI auf — das ist bei `bin/console` das Projektverzeichnis. Am Restore wird nichts geaendert | Claudes Entscheidung |
| Dumps pro Tabelle | Jede Tabellendatei einzeln herunterladbar, kein Sammel-Download | Annahme (bestaetigt) |
| Ohne Restore-Recht | Panel wird gar nicht angezeigt (nicht nur ausgegraut) | Annahme (bestaetigt) |
| Laden des Baums | Ordnerweise beim Aufklappen (lazy), nicht vorab komplett | Annahme (bestaetigt) |
| Download-Datei | Unveraendert ausliefern (kein Umpacken/Entpacken), gestreamt | Annahme (bestaetigt) |

## Technischer Rahmen

### Backend

Zwei neue API-Routen (`_routeScope: api`, ACL `muwa_backup_repository:restore` per
`PlatformRequest::ATTRIBUTE_ACL` — wie `RestoreSnapshotController`):

1. **Verzeichnis listen** — Eingabe: `backupRepositoryId` + relativer Pfad innerhalb des Restore
   Path (leer = Wurzel). Ausgabe: Unterordner und Dateien dieses einen Ordners, je Datei mit Name,
   relativem Pfad, Groesse, Aenderungsdatum und einem Flag "herunterladbar" (= ist Dump).
2. **Datei herunterladen** — Eingabe: `backupRepositoryId` + relativer Dateipfad. Antwort: die
   Datei als Stream (`BinaryFileResponse` o. ae.) mit `Content-Disposition: attachment`.
   Nur Dumps; alles andere wird abgelehnt.

Der Restore Path wird **serverseitig** aus dem Repository geladen, nie vom Client uebernommen.
Der Client schickt ausschliesslich Pfade **relativ** zum Restore Path.

Pflicht-Pruefungen (gemeinsam fuer beide Routen, in einem Service):

- Repository existiert, `restorePath` ist gesetzt und als Verzeichnis vorhanden — sonst eine
  definierte, leere bzw. Fehler-Antwort, die das Panel als "Noch nichts wiederhergestellt" /
  "Kein Restore Path konfiguriert" darstellen kann.
- Der Restore Path selbst ist zulaessig. Abgelehnt werden (gleiche Regeln wie
  `Settings::getOwnDumpPathRejection()`, idealerweise ueber dieselbe Regelquelle, damit sie nicht
  auseinanderlaufen): leer, `/`, Segmente `.`/`..`, das Projektverzeichnis oder ein Elternordner
  davon, die Blockliste gefaehrlicher System- und Projektordner, Ueberschneidung mit dem
  `repositoryPath` — und (Claudes Entscheidung) Ueberschneidung mit dem aufgeloesten Dump-Pfad
  des Repositories, damit der live geschriebene Dump nicht ueber das Panel ladbar ist.
  Bei Ablehnung antwortet die Directory-Route mit 200 und `state: 'notAllowed'` (+ `rejection`);
  Download-Prepare/-Redeem antworten mit 400 `MUWA_INVALID_PATH`. (Waehrend der Implementierung
  geaendert, weil das Panel den State braucht.) Das Panel zeigt "Restore Path nicht zulaessig"
  statt eines Baums. Restore und Configuration-Formular werden **nicht** geaendert.
- Aufgeloester Pfad liegt **innerhalb** des Restore Path: keine `..`-Segmente, keine absoluten
  Pfade, kein Null-Byte; nach `realpath()` muss der Pfad mit dem `realpath()` des Restore Path
  beginnen. Symlinks, die hinausfuehren, werden dadurch abgewiesen.
- Download nur, wenn die Datei eine regulaere, lesbare Datei ist und als Dump gilt
  (`.backup.sql.tar.gz`, `.backup.sql`, `.backup.sql.gz`).
- Ungueltige Eingaben: 400 mit eigenem Fehlercode (Muster wie `MUWA_INVALID_PATH` im
  `FilesystemController`).

Listen werden wie im `DirectoryBrowser` natuerlich sortiert und bei `MAX_ENTRIES` gekappt
(`truncated`-Flag), nicht-UTF-8-Namen uebersprungen.

### Administration

- Neues Panel im Tab `backupRepositorySnapshots` in
  `page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig`, sichtbar nur bei
  `acl.can('muwa_backup_repository.restore')`.
- Baum: Ordner aufklappbar, Inhalt wird beim ersten Aufklappen per API nachgeladen.
  Dump-Dateien als Link mit Groesse und Datum; andere Dateien sichtbar, nicht klickbar.
- Leerzustand, wenn nichts wiederhergestellt ist oder kein Restore Path gesetzt ist; eigener
  Hinweis, wenn der Restore Path nicht zulaessig ist.
- Aktualisieren-Moeglichkeit (z. B. ueber den bestehenden Refresh in der Sidebar oder einen
  eigenen Button), damit ein frisch abgeschlossener Restore sichtbar wird.
- Download muss den API-Token mitschicken (die Admin-API verlangt einen Bearer-Token; ein
  einfacher `<a href>` reicht nicht). Weg ist dem Builder ueberlassen, solange die Datei nicht
  komplett im Browser-Speicher landen muss, wenn es sich vermeiden laesst.
- Snippets in `snippet/de-DE.json` und `snippet/en-GB.json`.
- Muss auf Shopware 6.6 **und** 6.7 laufen (siehe Plugin-`CLAUDE.md`, Abschnitt
  "Shopware 6.6 und 6.7 parallel").

### Tests

- Unit-Tests fuer den Pfad-/Berechtigungs-Service, lauffaehig ueber `phpunit.unit.xml`:
  Traversal (`..`, absolut, Null-Byte), Symlink nach aussen, Nicht-Dump-Datei, fehlender Restore
  Path, leere Liste, Dump-Erkennung aller drei Endungen, relativer Restore Path, unzulaessiger
  Restore Path (`/`, Projektverzeichnis, Elternordner, Blockliste, Ueberschneidung mit Repository-
  bzw. Dump-Pfad).
- Services in `src/Resources/config/services.xml` registrieren (kein Autowiring).

## Dem Builder ueberlassen

- Namen von Routen, Controller, Service, Komponente und Snippet-Keys (im Stil der bestehenden
  `muwa`-Namen).
- Ob ein neuer Controller entsteht oder ein bestehender erweitert wird.
- Konkrete Baum-Komponente (eigene Komponente oder vorhandene Shopware-Komponente), solange sie in
  6.6 und 6.7 funktioniert.
- Wie der Download technisch im Browser ausgeloest wird (siehe Hinweis zum API-Token).
- Ob versteckte Dateien angezeigt werden.

## Offene Folgethemen (nicht Teil dieses Umfangs)

- Wiederhergestellte Dumps mit Kundendaten bleiben unbegrenzt im Restore Path liegen; ein
  Aufraeumen ist spaeter sinnvoll.
- Download weiterer Dateitypen (Files-Backups).
- Warnung bzw. Validierung des Restore Path direkt im Configuration-Formular (analog zur
  Live-Warnung am Dump-Pfad).
