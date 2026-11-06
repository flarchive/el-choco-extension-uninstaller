# Update von alpha.1 / alpha.2 auf rc.1

## Bestehendes CrazyMax-Forum

Alle Aufträge zuerst abschließen. Die Dienste werden nur für das Update gestoppt;
es wird kein Container neu erstellt und kein Laufzeitordner gelöscht.

1. `extension-uninstaller-0.1.0-rc.1.zip` nach `/mnt/wdblue/flarum2` übertragen.

2. Worker stoppen und die neue Quelle entpacken:

```bash
systemctl stop el-choco-uninstaller-flarum2.service
systemctl stop el-choco-admin-console.service
unzip -o /mnt/wdblue/flarum2/extension-uninstaller-0.1.0-rc.1.zip -d /mnt/wdblue/flarum2/data
```

Falls die Admin Console nicht installiert ist, deren Dienstbefehle weglassen.

3. Die Versionszuordnung der vorhandenen lokalen Quelle aktualisieren:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer config repositories.el-choco-uninstaller '{"type":"path","url":"/data/extension-uninstaller","options":{"symlink":false,"versions":{"el-choco/extension-uninstaller":"0.1.0-rc.1"}}}'
```

4. Update zuerst prüfen:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Nur nach erfolgreicher Prüfung ohne unerwartete Paketänderungen:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Bei einem Fehler hier stoppen; insbesondere die Erweiterungsliste nicht nach
einem fehlgeschlagenen Composer-Vorgang synchronisieren.

5. Web-PHP und die Worker auf den neuen Stand bringen:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
systemctl start el-choco-uninstaller-flarum2.service
systemctl start el-choco-admin-console.service
```

6. Browser mit Strg+F5 neu laden und eine neue Vorschau erstellen. Alte Pläne
werden wegen des geänderten Paketstands nicht mehr ausgeführt. Im Eingabefeld
steht nun `DELETE` bzw. `DISABLE` als Platzhalter. Keinen Paketnamen und keine
Versionsnummer mehr eingeben. Aufträge und vorhandene Sicherungen bleiben erhalten.

## Normale Installation

Für das bestehende native Flarum zuerst dessen Aufträge abschließen und die
betreffenden Worker stoppen. ZIP nach `/tmp` übertragen, nach `/opt/el-choco`
entpacken und die Quelle wie bei der Erstinstallation aktualisieren:

```bash
unzip -o /tmp/extension-uninstaller-0.1.0-rc.1.zip -d /opt/el-choco
cd /var/www/flarum
runuser -u www-data -- composer config repositories.el-choco-uninstaller '{"type":"path","url":"/opt/el-choco/extension-uninstaller","options":{"symlink":false,"versions":{"el-choco/extension-uninstaller":"0.1.0-rc.1"}}}'
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Nach erfolgreicher Prüfung den gleichen `composer require`-Befehl ohne
`--dry-run` ausführen, danach als `www-data` `php flarum cache:clear` aufrufen.
Den tatsächlich verwendeten PHP-FPM-Dienst neu laden und den nativen
`el-choco-uninstaller-flarum1.service` sowie gegebenenfalls die Admin Console
wieder starten. Für natives Flarum 2 die eigenen Pfade und Benutzer verwenden.
