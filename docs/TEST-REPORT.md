# Testbericht · 0.1.0-rc.2

Stand: 6. November 2026. Lokale Windows-Umgebung mit PHP 8.4.26 und Node 24.

## Automatisch geprüft

| Prüfung | Ergebnis |
|---|---|
| PHP-Syntax | Alle 24 PHP-Dateien bestanden |
| Composer-Paketdefinition | Strenge Validierung bestanden |
| Sicherheit, Planbindung, Schutzpakete, Queue und Snapshots | Je 32 Prüfungen mit Flarum 1.8.21 und 2.0.2 bestanden |
| Echte Flarum-Autorisierung, CSRF, Admin-API und Less-Compiler | Je 26 Prüfungen mit beiden Core-Versionen bestanden |
| Exportfehler, Optionsdatei, Zugangsdaten, Prüfsumme und Logtrennung | Je 10 Prüfungen mit simuliertem PDO-/Dump-Prozess bestanden |
| Optionale Admin-Console-Koordination mit echten Dateisperren | Je 8 Prüfungen mit beiden Abhängigkeitssätzen bestanden |
| Pfad-Metadaten, veränderte Referenzen/Quellen und installierter Paketstand | Je 24 Prüfungen mit beiden Abhängigkeitssätzen bestanden |
| Gebaute Frontends für Flarum 1 und 2 | 12 Tests bestanden |
| Produktions-Build beider Frontends | Bestanden |
| Statische RC2-Oberfläche | Im Headless-Browser gerendert und visuell geprüft |
| Echter Composer mit isolierten Offline-Paketen | Genau Ziel-Erweiterung und ungenutzte Bibliothek entfernt; geschützte Pakete erhalten |
| Echter Composer mit lokaler Git-/Path-Erweiterung | Vorschau, Lock-Normalisierung, Installation und anschließender Lock-Metadaten-Refresh bestanden |

Die Composer-Tests verändern nur eigene temporäre Testprojekte. Die Flarum-
Autoloader liefern echte Core-Abhängigkeiten, starten aber kein vollständiges
Forum mit Datenbank. Die SQL-Prüfungen ersetzen keinen tatsächlichen Export/Restore.

PHP 8.4 meldet Deprecations aus älteren Flarum-/Illuminate-Abhängigkeiten. Für die
abschließenden Testausgaben wurden diese bekannten Deprecations ausgeblendet;
fremde Abhängigkeiten wurden nicht verändert. Ein PHP-7.4-Lauf fand nicht statt.

## Vom Benutzer bestätigte Praxis

- Der Deinstallierer wurde im CrazyMax-Flarum-2-Forum als funktionierend gemeldet.
- Unter nativem Flarum 1 wurde nach dem RC1-Pfad-Hotfix der funktionierende Ablauf bestätigt.
- RC2 übernimmt den Fix und ergänzt den Abgleich gleichwertiger installierter Path-Pakete.

Diese Rückmeldungen sind keine separat beobachteten Tests aller Betriebsarten.
Das vollständige RC2-Paket muss nach dem Update auf den Zielsystemen geprüft werden.

## Noch gesondert zu prüfen

1. RC2 von Packagist aktualisieren; Worker und Web-OPcache neu laden.
2. Deaktivieren, Entfernen mit Datenbehalt und Neuinstallation jeweils prüfen.
3. Purge auf separater Datenbankkopie inklusive echtem SQL-Export und manuellem Restore.
4. Linux-Fehlerfälle für Exportrechte, Speicherplatz, Migration, Composer und Sync.
5. Abbruch/Wiederanlauf und gemeinsamer Live-Betrieb mit Admin Console.

RC2 bleibt ein Release Candidate. Keine entfernten Foren oder Datenbanken wurden
durch die lokalen automatischen Prüfungen verändert.
