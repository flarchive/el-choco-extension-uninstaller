# Hotfix: lokale Paketpfade in der Deinstallationsvorschau

Historische Anleitung für den eigenständigen RC1-Hotfix. **RC2 enthält die Korrektur bereits; diesen Hotfix nach dem Update nicht erneut einspielen.**

Für den bekannten RC.1-Dateistand von `el-choco/extension-uninstaller`.
Der Fix ersetzt ausschließlich `src/PlanService.php`. Er führt weder Composer
noch Migrationen aus und verändert keine Forumseinstellungen.

## Ursache und Korrektur

Composer kann für ein unverändertes lokales Path-Paket `dist.url` von relativ
auf absolut umschreiben und `transport-options.relative` anpassen. RC.1 hat
solche Unterschiede pauschal als Paketänderung abgelehnt.

Der Fix akzeptiert sie ausschließlich, wenn beide URLs im jeweils richtigen
Ausgangsverzeichnis auf dieselbe vorhandene physische Quelle zeigen und alle
anderen Paketdaten unverändert bleiben. Version, Referenz, Abhängigkeiten,
Autoload-Regeln und weitere Transportoptionen werden weiter geprüft. Bei
verschiedenen Quellen oder einer nicht nachweisbaren Übereinstimmung bleibt
die Vorschau gesperrt.

Für akzeptierte Pakete wird der bisherige Lock-Eintrag beibehalten. Dadurch
werden ihre Metadaten nicht bei der späteren Deinstallation umgeschrieben.
Die Datei-Prüfsummen des Hotfixes verhindern das Überschreiben unbekannter
Paketstände. Die Originaldatei wird vor dem Ersetzen privat gesichert.

## Normale Installation unter /var/www/flarum

Zuerst alle laufenden Aufträge abschließen. ZIP als
`/tmp/uninstaller-path-metadata-fix.zip` auf den Server übertragen.
Bei einem anderen Forum-Pfad oder PHP-Benutzer die Angaben entsprechend ändern.
Verwendet dein vorhandener Dienst einen anderen Namen, dessen Namen statt
`el-choco-uninstaller-native.service` verwenden; keinen neuen Dienst erstellen.

```bash
unzip -o /tmp/uninstaller-path-metadata-fix.zip -d /tmp
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
runuser -u www-data -- php /tmp/uninstaller-path-metadata-fix/apply.php --check
```

Nur nach erfolgreicher Prüfung:

```bash
runuser -u www-data -- php /tmp/uninstaller-path-metadata-fix/apply.php --apply && \
systemctl start el-choco-uninstaller-native.service
```

Die ausgegebene Sicherungs-ID aufbewahren. Anschließend im Adminbereich eine
**neue Vorschau** für die gewünschte Deinstallation erstellen. Es ist keine
Composer-Installation, keine Änderung weiterer Paketquellen und kein Cache-
Neuaufbau für diesen Worker-Fix erforderlich.

## CrazyMax Docker

ZIP nach `/mnt/wdblue/flarum2` übertragen. Nach Abschluss aller Aufträge:

```bash
unzip -o /mnt/wdblue/flarum2/uninstaller-path-metadata-fix.zip -d /mnt/wdblue/flarum2/data
systemctl stop el-choco-uninstaller-flarum2.service
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /data/uninstaller-path-metadata-fix/apply.php --check
```

Nur nach erfolgreicher Prüfung:

```bash
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /data/uninstaller-path-metadata-fix/apply.php --apply && \
systemctl start el-choco-uninstaller-flarum2.service
```

## Rücknahme

Worker stoppen und den vom Installer ausgegebenen Rücknahmebefehl als denselben
Forum-Benutzer im Forum-Verzeichnis ausführen. Beispiel für die normale Installation:

```bash
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
runuser -u www-data -- php /tmp/uninstaller-path-metadata-fix/apply.php --restore=SICHERUNGS-ID
systemctl start el-choco-uninstaller-native.service
```

`SICHERUNGS-ID` ersetzen. Die Sicherung liegt unter
`storage/extension-uninstaller/backups/path-metadata-...`. Nach einem späteren
Paketupdate keine alten Dateien unkontrolliert zurückkopieren.

## Prüfung

21 gezielte Fälle prüfen gleichwertige Pfade und weiterhin gesperrte Änderungen.
Ein realer Offline-Composer-Test mit lokaler Git-Path-Erweiterung prüft Vorschau,
Erhalt ihrer bisherigen Lock-Metadaten, Entfernung nur der bestätigten Pakete
und den anschließenden Abgleich mit den installierten Paketen. Die bisherigen
Sicherheitstests bleiben erhalten. Der Installer wird lokal lesend und auf
PHP-Syntax geprüft; das schreibende Einspielen auf deinem Linux-Forum muss
anschließend praktisch bestätigt werden.
