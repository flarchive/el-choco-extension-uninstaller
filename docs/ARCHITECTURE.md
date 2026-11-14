# Ausführung und Zustände

## Trennung

Die HTTP-API authentifiziert ausschließlich Administratoren und verwendet Flarums
CSRF-Middleware. Sie schreibt strukturierte Aufträge; sie führt keine Shell,
keinen Composer und keine Migration im HTTP-Prozess aus. `bin/worker.php` bootet
zum Lesen der Aufträge nur den Composer-Autoloader. Für eigentliche Flarum-Aktionen
wird ein separater PHP-Prozess mit der tatsächlichen `site.php` gestartet.

Der Worker muss als derselbe erkennbare Nicht-root-Benutzer wie Web-PHP laufen.
Ein Job benötigt dessen numerische UID. Kein Docker-Socket im Webprozess und
kein sudo aus der Oberfläche. Die systemd-Docker-Vorlage startet lediglich
`docker exec --user flarum` auf dem Host.

## Vorschau

1. Installierte Pakete aus `vendor/composer/installed.json` lesen.
2. Flarum-ID nach Flarums Namensregel ermitteln; direkte Anforderungen,
   Abhängigkeiten und geschützte Pakete prüfen.
3. Composer- und Aktivierungsstand sowie Erweiterungsdateien fingerprinten.
4. `composer.json` und `composer.lock` in einen privaten Planordner kopieren.
5. Dort `composer remove PAKET --no-install --update-no-dev --minimal-changes
   --no-scripts --no-plugins ...` ausführen.
6. Neuen Lock gegen den bisherigen prüfen: nur Entfernungen, keine neuen oder
   geänderten Pakete; keine andere Erweiterung und kein geschütztes Paket.
7. Plan mit zufälliger ID, Eigentümer und zehn Minuten Gültigkeit speichern.

Die Vorschau ist keine öffentliche Composer-`--dry-run`-Textanalyse. Sie löst in
einer isolierten Kopie auf und prüft die entstandenen strukturierten Dateien.
Nur die Vorschaukopie wird dabei geändert. Ein späterer Execute-Auftrag akzeptiert
keine vom Browser zurückgelieferten Paketlisten oder Dateiinhalte.

## Bestätigte Ausführung

Der Worker prüft den Plan erneut und verbraucht ihn genau einmal. Ein identischer
HTTP-Wiederholungsversuch liefert den vorhandenen Auftrag statt erneut auszuführen.

1. Dateirechte und Cache-/Asset-Verzeichnisse prüfen.
2. Paketkonfiguration und gegebenenfalls CrazyMax-Liste privat sichern.
3. Bei Purge: SQL-Dump mit `--single-transaction`, Triggern, Routinen und Events
   erstellen. Nur InnoDB-Basistabellen; Prozess muss Exit 0 melden, mindestens
   100 Bytes schreiben und die Datei vollständig flushen. SHA-256 erfassen.
   Zugangsdaten nur in einer temporären 0600-Optionsdatei; SQL nicht in Joblogs.
4. Über Flarums `ExtensionManager::disable()` deaktivieren; bei Purge anschließend
   `uninstall()` aufrufen. Letzteres ist eine interne Core-Schnittstelle und muss
   bei künftigen Core-Versionen weiter geprüft werden.
5. Bei Paketentfernung: Vorzustand nach dem Flarum-Hook nochmals prüfen, dann die
   bestätigten Composer-Dateien übernehmen und `composer install --no-dev
   --no-scripts --no-plugins ...` ausführen. Keine zweite freie Auflösung.
6. Entfernung anhand der installierten Metadaten bestätigen; erst danach
   CrazyMax `extension sync` ausführen.
7. Cache neu aufbauen und `php flarum info` als CLI-Startprüfung ausführen.

Die letzte Prüfung ist ausdrücklich **kein HTTP-200-Test**. Die Oberfläche muss
nach Änderungen neu geladen und das Forum praktisch geprüft werden. Webrequests
invalidieren die bekannten Composer-Autoload-Dateien im Web-OPcache; ein kompletter
PHP-FPM-Neustart erfolgt nicht aus der Anwendung.

## Sperren und Fehler

Die eigenen Queue-/Worker-Sperren und eine vorhandene Admin-Console-Worker-Sperre
verwenden `flock`. Die Admin Console muss nicht installiert sein. Die Sperre
verhindert parallele Ausführung der beiden Worker, verändert aber nicht deren
Quellcode und sperrt keine beliebigen Terminalbefehle.

Ein bereits „running“ gespeicherter Auftrag nach Worker-Verlust wird
„interrupted“. Nach Beginn eines Flarum-/Paket-Eingriffs führt auch ein
Folgeschrittfehler zu „interrupted“. Weitere Aufträge bleiben gesperrt. Dies ist
absichtlich konservativ, weil MySQL-DDL und Composer-Dateiänderungen keine
gemeinsame Datenbanktransaktion bilden. Kein automatisches Wiederholen und kein
automatisches Wiederherstellen von SQL oder Lock-Dateien.

`--acknowledge=ID` ist ausschließlich eine manuelle Freigabe nach Prüfung, keine
Reparatur. Sie darf erst erfolgen, wenn eventuell übrig gebliebene Kindprozesse
und der Forumszustand geprüft bzw. wiederhergestellt wurden.

## Speicherung

`storage/extension-uninstaller` enthält:

- `jobs/*.json`: Status, begrenzte Ausgabe und Ergebnis; 256 KiB Ausgabelimit.
- `plans/ID.json` und `plans/ID/`: private Einmalpläne und Solver-Dateien.
- `backups/ID/`: Composer-Dateien, optionale CrazyMax-Liste, Manifest und bei Purge SQL.
- `worker.json`, `pending.json`, `stop.json` und Sperrdateien.

Verzeichnisse sind privat, Dateien 0600. Webserver-Zugriff muss zusätzlich
ausgeschlossen sein. Protokolle werden nach sieben Tagen bzw. oberhalb von
100 Einträgen bereinigt; Pläne und Sicherungen in dieser ersten Version manuell
nach Aufbewahrungsplan. Keine Zugangsdaten oder SQL-Daten im Browserprotokoll.
