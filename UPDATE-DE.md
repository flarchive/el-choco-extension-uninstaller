# Update von RC1 auf RC2

Diese Anleitung verwendet die veröffentlichte Packagist-Version
`el-choco/extension-uninstaller:0.1.0-rc.2`. Die Befehle funktionieren erst,
wenn der GitHub-Tag `v0.1.0-rc.2` auf Packagist angezeigt wird.

RC2 enthält den bestätigten Fix für gleichwertige Pfadangaben lokaler Pakete.
Ein bereits eingespielter RC1-Hotfix wird durch das normale Paketupdate ersetzt.
Danach keinen separaten RC1-Hotfix mehr anwenden. Aufträge und Sicherungen unter
`storage/extension-uninstaller` bleiben erhalten.

Vorher laufende Aufträge abschließen, eine aktuelle Forumsicherung bereithalten
und andere Paketänderungen pausieren. Falls die Admin Console installiert ist,
auch deren Aufträge abschließen und ihren Worker während des Updates pausieren.
Es wird weder ein ZIP-Quellverzeichnis angelegt noch ein Container neu erstellt.

## A. Normale Installation ohne Docker

Beispiel: `/var/www/flarum`, PHP-Benutzer `www-data`, vorhandener Dienst
`el-choco-uninstaller-native.service`. Bei einem anders benannten Dienst dessen
Namen verwenden; keinen zweiten Dienst erstellen.

### 1. Worker stoppen

```bash
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
```

Eine vorhandene native Admin Console hat in unserem bisherigen Beispiel den
Dienst `el-choco-admin-console-flarum1.service`. Diesen gegebenenfalls ebenfalls
stoppen und am Ende wieder starten. Ohne Admin Console ist dieser Schritt unnötig.

### 2. Frühere lokale Quelle entfernen, falls vorhanden

Dieser Schritt ist nur für frühere Path-Testinstallationen. Die Quelle
`el-choco-uninstaller` kann auf einen lokalen Quellordner oder sogar auf das
installierte Paket unter `vendor/...` zeigen und eine Packagist-Version blockieren.

```bash
runuser -u www-data -- composer config --unset repositories.el-choco-uninstaller
```

Das entfernt nur diesen Repository-Eintrag aus der Forum-`composer.json`, keine
Paketdateien und keine Datenbankdaten. Andere Quellen, etwa `workbench` oder
FA-Picker-Quellen, beibehalten. Falls dieselbe Quelle ausdrücklich in der globalen
Composer-Konfiguration des Forum-Benutzers eingetragen wurde, sie dort ebenfalls
gezielt entfernen. Nicht pauschal alle Repositories löschen.

### 3. Update prüfen und installieren

```bash
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Bei unerwarteten zusätzlichen Paketänderungen stoppen. Nach erfolgreicher Prüfung:

```bash
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
runuser -u www-data -- php flarum cache:clear
```

### 4. Web-PHP und Worker neu laden

Den tatsächlich verwendeten PHP-FPM-Dienst neu laden, wenn OPcache alten Code
behält. Die vorhandenen Dienste lassen sich so anzeigen:

```bash
systemctl list-units --type=service 'php*-fpm.service'
```

Beispiel ausschließlich für ein Forum, das tatsächlich PHP 8.4-FPM verwendet:

```bash
systemctl reload php8.4-fpm
```

Danach den bisherigen Uninstaller-Dienst starten:

```bash
systemctl start el-choco-uninstaller-native.service
systemctl status el-choco-uninstaller-native.service --no-pager -l
```

Gegebenenfalls die zuvor pausierte Admin Console ebenfalls wieder starten.

## B. CrazyMax Docker

Beispiel: Container `flarum2-flarum-1`, interner Forum-Pfad `/opt/flarum`,
Container-Benutzer `flarum`, Dienst `el-choco-uninstaller-flarum2.service`.
Alle Befehle auf dem Docker-Host ausführen. `/opt/flarum` ist nur der interne
Container-Pfad und keine Vorgabe für eine normale Installation.

### 1. Worker stoppen

```bash
systemctl stop el-choco-uninstaller-flarum2.service
```

Falls installiert, den bisherigen Admin-Console-Dienst
`el-choco-admin-console.service` nach Abschluss seiner Aufträge ebenfalls pausieren.

### 2. Alte lokale Quelle gezielt entfernen

Nur bei einer früheren lokalen Testinstallation:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer config --unset repositories.el-choco-uninstaller
```

Das löscht keine Quelldateien und lässt andere Paketquellen bestehen. Eine
gegebenenfalls separat angelegte globale Quelle unter demselben Namen muss
zusätzlich in dieser globalen Composer-Konfiguration entfernt werden.

### 3. Update prüfen

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

### 4. Nach erfolgreicher Prüfung aktualisieren

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Bei einem Composer-Fehler stoppen. `extension sync` nur nach erfolgreichem Update.

### 5. Web-PHP und Worker neu starten

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
systemctl start el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

Eine zuvor pausierte Admin Console ebenfalls wieder starten. Kein Container-
Neuanlegen und keine Änderung an vorhandenen Volumes erforderlich.

## C. Nach dem Update

1. Browser mit Strg+F5 neu laden und die Anzeige `0.1.0-rc.2` prüfen.
2. Worker-Verbindung prüfen.
3. Eine neue Vorschau erstellen. Alte Pläne passen nach dem Paketupdate nicht mehr.
4. Paketliste und Ausgabe prüfen; `DELETE` bzw. `DISABLE` bleiben unverändert.

Falls die Vorschau weiterhin wegen einer relativen Repository-Konfiguration
abbricht, in [INSTALL-DE.md](INSTALL-DE.md#e-lokale-composer-paketquellen)
nachsehen. Der URL-Wert einer lokalen Quelle muss für die Vorschau absolut sein;
ihre Lock-Metadaten dürfen bei nachweislich derselben Quelle relativ bleiben.

Bei einer Fehlermeldung keine zusätzlichen Reparatur-/Rollback-Befehle blind
ausführen. Die ursprüngliche Ausgabe und den betreffenden Schritt prüfen.
