# Uebernahme bestehender restic-Repositories — offene Fragen

Stand 14.11.2026 (erstellt 13.11.2026). Gehoert zu
[`2026-11-12-repository-takeover-design.md`](2026-11-12-repository-takeover-design.md).

## Stand

Stand 14.11.2026: **Alle vier Fragen sind beantwortet** (jeweils wie empfohlen) und zusammen mit
den beiden bereits entschiedenen Punkten ins Design-Dokument eingetragen. Ausserdem neu im
Design: Einstieg ueber ein Auswahl-Modal am Button "Repository anlegen" statt eigener Seite,
Uebernahme sofort nach erfolgreicher Pruefung, Name aus dem Ordnernamen.

Offen ist nur noch die Freigabe des Design-Dokuments. Danach den Umsetzungsplan schreiben
(`superpowers:writing-plans`), Ablage unter `docs/superpowers/plans/`. Es wurde noch nichts
gebaut.

Dieses Dokument bleibt als Protokoll der Entscheidungen stehen.

## Offene Fragen

### 1. Darf die Library `muckiware/restic` mit angepasst werden?

Die Library (v1.5.0, im Plugin `^1.4`) kann das Design an zwei Stellen nicht sauber erfuellen:

- `Library/CommandLine/Commands/Check.php` ist fest auf `check --read-data` verdrahtet
  (siehe Frage 2).
- `Library/Manage/Local.php::getSnapshots()` mappt bei JSON-Ausgabe jeden Snapshot per
  `JsonMapper` auf die typisierte Klasse `Snapshot`, bevor es etwas zurueckgibt. Scheitert das
  Mapping an einem fremden Snapshot (z. B. ohne `summary`), bricht der ganze Aufruf ab. Ob es
  tatsaechlich scheitert, ist **nicht ausprobiert**.
- `Commands/Snapshots.php` kennt kein `--no-lock` (relevant fuer Frage 4).

Im Plugin laesst sich das nicht umgehen, weil eigene `Process`/`exec`-Aufrufe dort
ausgeschlossen sind (Arbeitsregel in `CLAUDE.md`).

**Warum es den Plan aendert:** Mit Library-Aenderung gibt es ein zweites Repository mit eigenem
Release und fester Reihenfolge (erst Library, dann Plugin). Ohne bleiben Workarounds im Plugin
und ein bewusst akzeptiertes Restrisiko.

**Empfehlung:** Ja. Ein neues Release von `muckiware/restic` gehoert in den Plan, das Plugin hebt
die Mindestversion an.

**Antwort (14.11.2026):** Ja, wie empfohlen. Neues Library-Release vor dem Plugin, Mindestversion wird angehoben. Im Design eingetragen.

### 2. Wie tief soll die Pruefung nach der Uebernahme gehen?

Der einzige vorhandene Weg (`Backup\Local::checkBackup()`) liest mit `--read-data` alle
Pack-Daten. Bei einem ueber Jahre gewachsenen Repository dauert das Stunden, blockiert so lange
einen Messenger-Worker und haelt eine Sperre, waehrend der alte Cronjob womoeglich noch laeuft.

**Warum es den Plan aendert:**

- Strukturpruefung ohne `--read-data` braucht die Library-Aenderung aus Frage 1.
- Voll-Check braucht Vorgaben zu Prozess- und Worker-Zeitlimits, und Status-Tab sowie
  Benachrichtigung kommen womoeglich erst Stunden spaeter; der Hinweistext muesste das sagen.

**Empfehlung:** Nach der Uebernahme nur die Strukturpruefung ohne `--read-data` (Minuten statt
Stunden). Die volle Pruefung laeuft wie gewohnt nach dem ersten Backup ueber das Plugin.

**Antwort (14.11.2026):** Nur Strukturpruefung ohne `--read-data`, wie empfohlen. Im Design eingetragen.

### 3. Welchen Hostnamen bekommt das uebernommene Repository?

Das Design sagt "Hostname leer". Das gibt es so nicht: `InitBackupRepositoryController` setzt den
Hostnamen nur bei nicht leerer Eingabe, sonst greift der DTO-Default
`PluginDefaults::DEFAULT_REPOSITORY_HOST_NAME` (`muwa-facility-client`) in
`BackupRepositorySettings`. `FilesRunner` gibt ihn per `setHostName()` an restic weiter.

Das zaehlt, weil restic `forget` nach Host und Pfaden gruppiert. Sichert das Plugin kuenftig
unter einem anderen Hostnamen als der alte Cronjob, bilden die uebernommenen Snapshots eine
eigene Gruppe. Die bekommt nie neue Snapshots, also bleiben ihre letzten Staende dauerhaft
stehen, statt mit den neuen zusammen ausgeduennt zu werden. Die Entscheidung "fallen ganz normal
unter die Forget-Regeln" saehe fuer den Nutzer dann anders aus als erwartet.

Moeglich sind: wirklich leerer String (restic nimmt den System-Hostnamen, im Container den
Container-Namen), der Plugin-Default, oder der Host des juengsten uebernommenen Snapshots.

**Warum es den Plan aendert:** Payload, Tests und Hinweistext.

**Empfehlung:** Der Hostname des juengsten uebernommenen Snapshots wird vorbelegt. Er bleibt im
Tab Konfiguration aenderbar (das Feld gibt es dort schon). Bei einem Repository ohne Snapshots
greift der Plugin-Default.

**Antwort (14.11.2026):** Hostname des juengsten Snapshots, aenderbar; ohne Snapshots Plugin-Default. Im Design eingetragen.

### 4. Repository lesbar, aber fuer den Webserver-Benutzer nicht schreibbar: ablehnen oder uebernehmen?

Typischer Fall: ein Repository, das root per Cron betrieben hat. Das Design prueft in Schritt 2
der Uebernahme-Route bisher nur "lesbar".

- Ohne `--no-lock` scheitert schon `restic snapshots` an der Sperrdatei, mit einer rohen
  restic-Meldung.
- Mit `--no-lock` gelaenge die Uebernahme, aber Check, Backup und Forget schluegen danach fehl;
  das Repository waere nur fuer Restores nutzbar.

**Warum es den Plan aendert:** Entweder kommt eine Schreibbarkeitspruefung mit eigenem Fehlercode
(`MUWA_TAKEOVER_*`) in Schritt 2, oder die Nur-Lese-Uebernahme wird bewusst zugelassen und im
Text erklaert.

**Empfehlung:** Vorab Schreibrechte pruefen und mit einer verstaendlichen Meldung ablehnen, die
sagt, welcher Benutzer Schreibrecht auf welchen Pfad braucht.

**Antwort (14.11.2026):** Vorab Schreibrechte pruefen und mit verstaendlicher Meldung ablehnen, wie empfohlen. Im Design eingetragen.

## Ins Design eingetragen am 14.11.2026 (Claudes Entscheidung)

- **"Nur lesen" praezisieren:** Gemeint ist kein `init`, `unlock`, `prune`, `forget` oder Backup
  im Zuge der Uebernahme. Die kurzlebige Sperrdatei, die restic bei `snapshots` und `check`
  selbst anlegt, ist damit vereinbar. Betrifft das erste Anti-Ziel.
- **Dump-Ordner aus den Pfad-Vorschlaegen filtern:** Der Dump-Ordner des Plugins (eigener
  `dbDumpPath` bzw. Default `var/db/backup`) taucht in den Snapshots selbst angelegter
  Repositories als Pfad auf und wuerde als "nicht gefunden" vorgeschlagen. Er wird
  herausgefiltert, weil das Plugin ihn selbst sichert.

## Vom Reviewer geprueft, keine Frage

- **Inaktiv nach Uebernahme:** `active` am Repository wird im Backend nirgends ausgewertet.
  Backups starten nur ueber einen separaten Zeitplan-Datensatz, Forget nur per CLI. Das Anti-Ziel
  "startet von selbst" ist ohne Zusatzarbeit erfuellt.
- **Passwort:** geht per Umgebungsvariable `RESTIC_PASSWORD` an restic, nicht als
  Prozess-Argument.
- **Benachrichtigung:** Muster steht in `Schedule/BackupExecution::notify()`.

## Im Gespraech bereits entschieden (zur Orientierung)

Vollstaendig in der Tabelle "Entscheidungen" des Design-Dokuments. Kurzfassung:

- ~~Eigene Seite, Einstieg ueber einen zweiten Button~~ — ersetzt am 14.11.2026: Der Button
  "Repository anlegen" oeffnet ein Modal mit der Wahl "neu / uebernehmen".
- Eingaben: lokaler Pfad und Passwort (Name aus dem Ordnernamen); danach Sprung in den Tab
  Konfiguration.
- Repository ist nach der Uebernahme inaktiv, die uebrige Konfiguration bleibt leer.
- Im Klick: `restic snapshots` (Zugriffspruefung und Import) und Datensatz. Ueber die Message
  Queue: Check und Statistik.
- Uebernommene Snapshots fallen unter die normalen Forget-Regeln des Plugins.
- Backup-Pfade aus den Snapshots als Vorschlagsliste zum Anklicken, nicht automatisch.
- Nur lokale Pfade, kein CLI-Befehl, Passwort verschluesselt gespeichert.
