# Testbericht · 0.1.0-rc.1

Stand: 6. November 2026. Lokale Windows-Entwicklungsumgebung, PHP 8.4.26 und Node 24.

RC.1 übernimmt die Bestätigungswörter DELETE/DISABLE und getrennte Versionsangaben. Der Banner verwendet #4d698e, normale Schreibweise und einen sachlichen Beschreibungstext; die bisherige große Überschrift entfällt.
Die vorherige alpha.1 wurde vom Benutzer im CrazyMax-Forum als funktionierend
gemeldet. Das bestätigt noch keinen gesonderten Purge-/Datenbank-Restore-Test
und keinen Live-Test der neuen RC.1.

## Durchgeführt

| Prüfung | Ergebnis |
|---|---|
| PHP-Syntax aller Erweiterungsdateien | Bestanden |
| Sicherheit, Planbindung, Manipulation, Schutzpakete, Queue und Snapshots mit echten Flarum-1.8.21-Abhängigkeiten | 32 Prüfungen bestanden |
| Dieselben Prüfungen mit Flarum-2.0.2-Abhängigkeiten | 32 Prüfungen bestanden |
| Echte Flarum-Autorisierung, CSRF, Admin-API, idempotente Bestätigung und Less-Compiler, pro Core-Version | Je 26 Prüfungen bestanden |
| Datenexportfehler, Optionsdatei, geheime Zugangsdaten, Prüfsumme und Logtrennung | 10 Prüfungen mit simuliertem PDO-/Dump-Prozess bestanden |
| Optionale Admin-Console-Koordination mit echten Dateisperren und offenen Auftragszuständen | 8 Prüfungen bestanden |
| Gebaute Frontends für Flarum 1 und 2: Registrierung, Übersetzung, Bestätigung, Retry und Polling | 12 Tests bestanden |
| Tatsächlicher Composer in eigenem Offline-Testprojekt | Vorschau ohne Änderung des Live-Testprojekts; anschließend genau Erweiterung und ungenutzte Bibliothek entfernt, geschützte Pakete erhalten |
| Produktions-Build beider Frontends | Bestanden |
| Desktop-Designvorschau im Headless-Browser | Gerendert und visuell geprüft |

Die „echten Flarum-Abhängigkeiten“ umfassen die tatsächlichen User-, Group-, Gate-,
CSRF-, Extender- und Less-Klassen. Sie bedeuten nicht, dass ein vollständiges Forum
mit echter Datenbank gestartet wurde. Der Composer-Test verwendet künstliche
Paketdefinitionen, aber den tatsächlichen Composer-Solver und Installer. Er
verändert nur eigens angelegte Testverzeichnisse.

Im Flarum-1-Test meldet PHP 8.4 bestehende Deprecations aus den älteren
Core-/Illuminate-Abhängigkeiten. Die Prüfungen bestehen; diese Meldungen wurden
nicht durch Änderungen an fremden Paketen unterdrückt oder „repariert“.

## Vor Produktionsfreigabe noch erforderlich

1. Installation und Aktivierung in einem echten Flarum 1.8 sowie Flarum 2 unter Linux.
2. Dauerworker/systemd, Abbruch und Wiederanlauf im CrazyMax-Container praktisch prüfen.
3. Deaktivieren einer Test-Erweiterung; Bestätigung von UI, Cache und Forum.
4. Mit erhaltenen Daten entfernen, neu installieren und erhaltene Daten nachweisen.
5. Purge einer Test-Erweiterung auf separater MySQL-/MariaDB-Kopie inklusive
   tatsächlichem SQL-Export, Rückwärtsmigrationen und erfolgreichem manuellem Restore.
6. Exportrechte-, Plattenplatz-, Migration-, Composer- und Sync-Fehler auf Linux testen.
7. Gemeinsamen Betrieb mit der installierten Admin Console praktisch prüfen.
8. Web-OPcache, tatsächliche HTTP-Erreichbarkeit und beide Anzeige-Sprachen im Forum prüfen.

Der Deinstallierer wurde nicht auf dem DietPi installiert und keine dortige
Erweiterung oder Datenbank wurde durch diese Entwicklung verändert. Diese Version
bleibt eine Testversion; sie ist nicht als produktionsfertig ausgezeichnet.
