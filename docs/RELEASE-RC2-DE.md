# Extension Uninstaller 0.1.0-rc.2

RC2 korrigiert abgelehnte Vorschauen bei unveränderten lokalen Path-Paketen und
enthält überarbeitete Packagist-Installations- und Update-Anleitungen.

## Änderungen

- URL-/Relative-Flag-Änderungen werden nur akzeptiert, wenn beide Pfade auf dieselbe
  vorhandene Quelle zeigen und alle übrigen Paketdaten identisch bleiben.
- Die bisherigen Lock-Einträge unveränderter lokaler Pakete bleiben erhalten.
- Auch der Abgleich installierter Pakete mit dem Lock erkennt gleichwertige Pfade
  nach einem Composer-Metadaten-Refresh.
- Geänderte Versionen, Referenzen, Quellen, Abhängigkeiten und Autoload-Regeln
  bleiben gesperrt.
- Ablehnungen nennen jetzt das betreffende Paket und die geänderten Feldnamen.
- Alte ZIP-/Path-Testschritte sind durch Packagist-Anleitungen ersetzt.
- Vollständige systemd-Beispiele für normale Installation und CrazyMax Docker.
- Eigene Update-Anleitungen in Deutsch und Englisch, inklusive gezielter Entfernung
  der überholten lokalen Uninstaller-Quelle beim Packagist-Wechsel.

Der Benutzer hat den funktionierenden Ablauf unter nativem Flarum 1 nach dem
RC1-Pfad-Hotfix bestätigt. RC2 enthält diesen Fix und zusätzliche Prüfungen des
installierten Paketstands. Nach dem Update keinen RC1-Hotfix mehr einspielen.

## Update

Vor den Befehlen aus [UPDATE-DE.md](../UPDATE-DE.md) muss `v0.1.0-rc.2` als Tag
veröffentlicht und auf Packagist sichtbar sein. Aufträge abschließen, Worker
stoppen, aktualisieren und denselben Dienst neu starten. Kein Dienst-Neuanlegen
und kein Löschen von Zustandsordnern nötig. RC2 bleibt ein Release Candidate;
vollständige SQL-Export-/Restore- und Fehlerwiederherstellungstests werden nicht
als abgeschlossen ausgewiesen.
