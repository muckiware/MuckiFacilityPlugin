# Ordner-Browser fuer Pfadfelder im Backup-Repository

Design-Dokument, 25.10.2026. Plugin `muckiware/facility-plugin`.

## Problem

Alle Pfade eines Backup-Repositories werden in der Administration von Hand eingetippt:
Repository Path, Restore Path, Database dump path und die Backup Paths. Wer den Server nicht
auswendig kennt, muss vorher per Shell nachsehen, wie die Ordner heissen. Tippfehler fallen erst
beim Backup- oder Restore-Lauf auf, beim Dump-Pfad sogar gar nicht: `Settings::resolveOwnDumpPath()`
verwirft unsichere Werte still (nur Log-Eintrag) und faellt auf `<projectDir>/var/db/backup`
zurueck.

## Ziel

Ein Klick in eines dieser Pfadfelder klappt direkt darunter eine Liste der Ordner auf dem
Server bzw. Container auf, auf dem Shopware laeuft. Man navigiert per Klick und uebernimmt einen
Ordner in das Feld, statt den Pfad zu tippen.

## Nicht-Ziele

- **Kein Anlegen, Umbenennen oder Loeschen von Ordnern.** Der Browser ist rein lesend. Fuer ein
  neues restic-Repository waehlt man den vorhandenen Elternordner und schreibt den Namen des neuen
  Ordners von Hand ans Ende; restic legt ihn beim Initialisieren selbst an.
- **Keine Dateien.** Es werden nur Ordner angezeigt.
- **Keine Startgrenze / kein Chroot.** Sichtbar ist alles, was der PHP-/Webserver-User lesen darf,
  auch ausserhalb des Projekts (z. B. `/mnt`, `/var/backups`). Das ist gewollt: externe
  Backup-Mounts sind ein bestehender Anwendungsfall.
- Der Repository Path wird im Tab Configuration **nicht** editierbar. Er bleibt nach dem Anlegen
  gesperrt (`:disabled="true"`), dort gibt es keinen Browser.
- Der Ordner auf dem Rechner des Admin-Users (lokales Dateisystem des Browsers) ist nicht gemeint.

## Anti-Ziele

Es waere ein Fehlschlag, auch wenn es technisch funktioniert, wenn

- die neue Route Ordner auflisten laesst, ohne dass der User Bearbeitungsrechte am
  Backup-Repository hat — sie verraet die Ordnerstruktur des Servers;
- die Route ueber manipulierte Eingaben (Null-Bytes, Steuerzeichen, ueberlange Pfade) etwas
  anderes tut als Unterordner eines Ordners zu listen, oder Dateiinhalte/Dateinamen ausliefert;
- das Feld kein freies Textfeld mehr ist (Tippen, Einfuegen, Ergaenzen muss weiter gehen);
- der Browser auf 6.6 oder 6.7 nicht funktioniert.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Welches Dateisystem | Server/Container, auf dem Shopware laeuft |
| Sichtbarkeit | Alles, was der PHP-Prozess lesen darf; keine kuenstliche Grenze |
| Darstellung | **Variante A:** Aufklapp-Liste direkt unter dem Feld (kein Modal) |
| Oeffnen | Klick/Fokus ins Feld oder auf ein Ordner-Symbol im Feld (Suffix) |
| Inhalt der Liste | Pfadleiste (Breadcrumb, jedes Segment klickbar), Eintrag „..“, Unterordner; Footer-Button „Diesen Ordner waehlen“ |
| Navigation | Klick auf Ordner = hineinwechseln; „Diesen Ordner waehlen“ schreibt den aktuell geoeffneten Ordner ins Feld und schliesst die Liste |
| Nicht lesbare Ordner | Werden angezeigt, aber ausgegraut mit Schloss und Hinweis „keine Leserechte“, nicht anklickbar |
| Versteckte Ordner (`.git` usw.) | Werden angezeigt, aber blasser dargestellt |
| Startordner | Feld leer → Shopware-Projektverzeichnis (`kernel.project_dir`). Feld enthaelt Pfad → dieser Ordner, bzw. der naechste existierende Elternordner (weil am Ende oft ein noch nicht existierender Name steht) |
| Ordner anlegen | Nein (siehe Nicht-Ziele) |
| Rechte | Nur mit Bearbeitungsrecht am Repository (ACL `muwa_backup_repository:update`, das die Rollen editor und creator beide haben) |
| Warnung Dump-Pfad | Beim Database dump path erscheint unter dem Feld eine Warnung, wenn der Wert vom Backup abgelehnt wuerde oder der Ordner nicht beschreibbar ist — fuer jeden Feldwert (Tippen, Einfuegen, Auswahl, Oeffnen der Seite), siehe Teil 2 |

### Einsatzorte

| Seite | Felder mit Browser |
|---|---|
| Anlegen (`muwa-backup-repository-create`) | Repository Path, Restore Path, Database dump path, jede Zeile Backup Paths |
| Detail, Tab Configuration (`muwa-backup-repository-detail`) | Restore Path, Database dump path, jede Zeile Backup Paths. Repository Path bleibt gesperrt, ohne Browser |

## Teile

### 1. API-Route: Ordner auflisten

Neue Route im bestehenden Muster (`#[Route]`-Attribut, `_routeScope: api`, Pfad unter
`/api/_action/muwa/...`, Controller in `services.xml` registriert, kein Autowiring), z. B.
`POST /api/_action/muwa/filesystem/directories` mit ACL
`PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']`. Die Route muss in
`tests/Controller/RouteAclTest.php` mit abgedeckt werden.

Eingabe: optionaler Pfad. Leer → Projektverzeichnis.

Verhalten:
- **Der Browser schreibt immer absolute Pfade ins Feld** (Entscheidung Claude). Hintergrund: nur
  der Database dump path wird im Backend relativ zum Projektverzeichnis aufgeloest
  (`Settings::resolveOwnDumpPath()`); `repositoryPath`, `restorePath` und `backupPaths` gehen
  unveraendert an restic bzw. `is_dir()` und haengen bei relativen Werten vom Arbeitsverzeichnis
  des jeweiligen Prozesses ab. Absolute Pfade sind fuer alle Felder eindeutig. Das
  Backend-Verhalten fuer relative Werte wird **nicht** geaendert.
- Steht im Feld beim Oeffnen ein relativer Wert, dient er nur als Startpunkt und wird dafuer
  relativ zum Projektverzeichnis gelesen.
- **Logische Pfade, kein `realpath()`** (Entscheidung Claude). Ins Feld kommt der Pfad, durch den
  man navigiert ist, nicht das Symlink-Ziel — sonst landet z. B. bei Deployer-Layouts
  (`current`/`shared`) ein Release-Ordner im Feld, der beim naechsten Deploy verschwindet. Die
  Dump-Pfad-Pruefung vergleicht ebenfalls Strings, das bleibt damit konsistent. Pfade werden nur
  lexikalisch normalisiert (doppelte `/`, abschliessender `/`); Segmente `.`/`..`, Null-Bytes und
  Steuerzeichen → 400. „..“ in der Liste ist reine UI-Navigation zum Elternpfad (String kuerzen).
- Verlinkte Ordner werden angezeigt (mit Link-Kennzeichnung) und koennen betreten werden.
- Existiert der Startpfad nicht, zum naechsten existierenden Elternordner hochgehen.
- Antwort: der aufgeloeste aktuelle Pfad, dessen Elternpfad (oder `null` bei `/`), und die
  Unterordner, je mit Name, absolutem Pfad, `readable`, `hidden`. Alphabetisch sortiert.
- Nur Verzeichnisse, keine Dateien, keine Dateiinhalte. Fehler (nicht lesbar, existiert nicht)
  als saubere JSON-Fehlerantwort, nie als 500 mit Stacktrace.
- Obergrenze fuer die Anzahl Eintraege (Ordner mit zehntausenden Unterordnern), mit Hinweis
  „Liste gekuerzt“ in der Antwort.

Logik in einen eigenen Service (z. B. `Services\DirectoryBrowser`) statt in den Controller, damit
sie per Unit-Test (`phpunit.unit.xml`) testbar ist.

### 2. API: Pruefung des Dump-Pfads

Die Warnung beim Database dump path muss **dieselbe** Pruefung nutzen wie das Backup
(`Settings::resolveOwnDumpPath()` inkl. Blockliste, Projekt-/Elternverzeichnis, `..`,
Ueberschneidung mit Repository- und Restore-Path). Keine zweite Kopie der Regeln im JavaScript.
Dazu wird die Pruefung so herausgeloest, dass sie einen Ablehnungsgrund zurueckgeben kann, und
ueber die API erreichbar gemacht (eigene Route oder Teil der Listen-Antwort, Builder-Entscheidung).
Die Admin zeigt den Grund unter dem Feld an. Speichern wird **nicht** verhindert — das Backup
faellt wie bisher auf den Default zurueck; es geht nur um sichtbares Feedback.

**Wann geprueft wird (Entscheidung Maintainer):** fuer jeden Feldwert, nicht nur nach einer
Auswahl im Browser — also auch beim Tippen (debounced), beim Einfuegen und beim Oeffnen der Seite
mit einem bereits gespeicherten Wert. Aenderungen an Repository Path oder Restore Path loesen die
Pruefung ebenfalls neu aus, weil die Ueberschneidungsregel von ihnen abhaengt. Die Pruefung laeuft
damit unabhaengig davon, ob die Liste offen ist; Voraussetzung ist dasselbe Recht wie fuer den
Browser.

**Auch „nicht beschreibbar“ melden (Entscheidung Claude):** `Settings::getBackupPath()` faellt
ebenfalls still auf den Default zurueck, wenn `ensureDirectoryExists()` scheitert. Die Pruefung
meldet deshalb zusaetzlich, wenn der Ordner existiert, aber nicht beschreibbar ist, bzw. nicht
existiert und sein naechster existierender Elternordner nicht beschreibbar ist. Die Pruefung legt
dabei **nichts** an (rein lesend, `is_writable()`). Diese Warnung ist als Hinweis formuliert, denn
Webserver-User und Messenger-Worker/Cron-User koennen sich unterscheiden.

### 3. Admin-Komponente

Neue modul-lokale Komponente unter `component/`, z. B. `muwa-backup-repository-path-field`, die
ein `sw-text-field` plus Aufklapp-Liste kapselt. Sie wird an allen Einsatzorten statt des
bisherigen `sw-text-field` verwendet und muss sich wie dieses binden lassen (`v-model:value`).

- Klassischer Options-API-Stil wie der Rest des Moduls (`Component.register`, `this.$t`), HTTP
  ueber `Shopware.Application.getContainer('init').httpClient` mit `getApiHeader()` wie in den
  Seiten.
- Muss auf 6.6 und 6.7 laufen; Template-Muster und Fallen siehe CLAUDE.md, Abschnitt
  „Shopware 6.6 und 6.7 parallel“ (kein `$listeners`, kein `slot=`-Attribut, `$t` statt `$tc`
  mit Named-Values).
- Liste schliesst bei Klick ausserhalb, bei Escape und nach Auswahl. Tastaturbedienung
  (Pfeiltasten/Enter) ist wuenschenswert, nicht Pflicht.
- Fehlt das Recht `muwa_backup_repository:update`, erscheint weder Ordner-Symbol noch Liste; das
  Feld verhaelt sich wie heute.
- Lade- und Fehlerzustand in der Liste sichtbar (z. B. „Ordner nicht lesbar“).
- Pflichtfeld-, `maxlength`- und Placeholder-/Help-Text-Verhalten der bisherigen Felder bleibt
  erhalten.
- Snippets in `snippet/de-DE.json` und `en-GB.json`.

### 4. Einbau

Die bisherigen `sw-text-field`-Paare (`isV6600`/`isV6500`) der genannten Felder in
`muwa-backup-repository-create.html.twig` und `muwa-backup-repository-detail.html.twig` (inkl.
der Grid-Spalte `column-backupPath`) durch die neue Komponente ersetzen. Der Repository Path auf
der Detail-Seite bleibt unveraendert gesperrt. Bei den Backup Paths sitzt die Liste in einer
Grid-Zeile — darf nicht vom Grid abgeschnitten werden.

## Rahmen

- Plugin-Konventionen aus `CLAUDE.md` gelten (Namespace `MuckiFacilityPlugin\`, Services in
  `services.xml`, Stil konsistent mit bestehendem Code, keine LightsOn-Standards).
- Kompiliertes Admin-JS liegt versioniert unter `src/Resources/public/administration/` und muss
  nach der Aenderung neu gebaut werden.
- PHP-Tests: Unit-Tests ueber `phpunit.unit.xml` fuer den Listen-Service und die herausgeloeste
  Dump-Pfad-Pruefung; Route in `RouteAclTest`. Fuer JS gibt es keine Test-Infrastruktur im Plugin
  — Admin wird manuell im Browser auf 6.7 (sw67) getestet.
- `CHANGELOG.md` und Version bumpen (Feature → Minor).
- Nicht committen; der Maintainer sichtet den Diff und committet selbst.

## Dem Builder ueberlassen

- Genaue Routen-Namen und ob Listen und Dump-Pfad-Pruefung eine oder zwei Routen sind
- Name und interne Struktur der Komponente und des Services
- Hoehe der Eintrags-Obergrenze
- Ob Tippen im Feld die Liste live auf den getippten Ordner nachfuehrt (wuenschenswert)
- Visuelle Details innerhalb von Variante A (Mockup:
  `.superpowers/brainstorm/28662-1792920328/content/browser-layout.html`, linke Karte)
