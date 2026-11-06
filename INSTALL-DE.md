# Installation und erster Test

Diese Anleitung gilt für den lokalen Release Candidate `0.1.0-rc.1`. Sie ersetzt
weder die bestehende Admin Console noch deren Dienst. Den Deinstallierer zuerst
auf einer gesicherten Testinstallation erproben.

## A. Flarum 2 im bestehenden CrazyMax-Container

Ausgangslage: Host `/mnt/wdblue/flarum2`, Container `flarum2-flarum-1`,
Forum im Container `/opt/flarum`, Benutzer `flarum`, `/data` bereits eingebunden.
Alle Befehle dieses Abschnitts auf dem DietPi-Host ausführen. Keine Container-
Neuerstellung und keine zusätzliche Portainer-Volume-Konfiguration erforderlich.

### 1. Dateien übertragen

Das ZIP `extension-uninstaller-0.1.0-rc.1.zip` nach `/mnt/wdblue/flarum2`
übertragen. Es enthält genau den Unterordner `extension-uninstaller`.

```bash
unzip -o /mnt/wdblue/flarum2/extension-uninstaller-0.1.0-rc.1.zip -d /mnt/wdblue/flarum2/data
docker exec flarum2-flarum-1 ls -l /data/extension-uninstaller/composer.json
```

Die Paketdateien müssen für `flarum` lesbar sein. Das ZIP enthält normale Lese-
und Verzeichnisrechte. Nicht in `storage/extension-uninstaller` entpacken: Dort
liegen später private Aufträge und Sicherungen.

### 2. Lokale Composer-Quelle eintragen

Vorher laufende Admin-Console-Aufträge abschließen und deren Worker kurz stoppen:

```bash
systemctl stop el-choco-admin-console.service
```

Die folgende Quelle ist absichtlich absolut und verwendet eine Kopie statt
eines Symlinks. Kein bestehendes Repository und keine Erweiterungsliste löschen.

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer config repositories.el-choco-uninstaller '{"type":"path","url":"/data/extension-uninstaller","options":{"symlink":false,"versions":{"el-choco/extension-uninstaller":"0.1.0-rc.1"}}}'
```

Zuerst die Installation nur prüfen:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Bei unerwarteten Updates/Entfernungen hier stoppen. Nach erfolgreicher Prüfung:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum extension:enable el-choco-extension-uninstaller && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

`extension sync` ausschließlich nach erfolgreicher Composer-Installation.
Danach PHP-FPM neu laden und den bisherigen Admin-Console-Worker wieder starten:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
systemctl start el-choco-admin-console.service
```

### 3. Eigenen Worker-Dienst einrichten

Die Datei `resources/el-choco-uninstaller-flarum2.service` auf den Host nach
`/etc/systemd/system/el-choco-uninstaller-flarum2.service` kopieren. Enthalten ist:

```ini
[Unit]
Description=el-choco Extension Uninstaller (Flarum 2 Docker)
After=docker.service
Requires=docker.service
StartLimitIntervalSec=0

[Service]
Type=simple
ExecStart=/usr/bin/docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/opt/flarum --watch
ExecStop=-/usr/bin/docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/opt/flarum --stop
Restart=always
RestartSec=5
TimeoutStopSec=infinity

[Install]
WantedBy=multi-user.target
```

Bei unveränderten Pfaden kann die mitgelieferte Datei direkt kopiert werden:

```bash
cp /mnt/wdblue/flarum2/data/extension-uninstaller/resources/el-choco-uninstaller-flarum2.service /etc/systemd/system/el-choco-uninstaller-flarum2.service
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

Dieser Dienst ist unabhängig vom Admin-Console-Dienst. Der Worker prüft neue
Aufträge ungefähr jede Sekunde; kein Minuten-Cron und keine feste 20-Sekunden-Pause.

### 4. Datenbereinigung vorbereiten

Für „Daten behalten“ und „Nur deaktivieren“ ist kein Datenbank-Dump-Programm nötig.
Für „Daten bereinigen“ muss es **im Flarum-Container** verfügbar sein:

```bash
docker exec flarum2-flarum-1 sh -c 'command -v mariadb-dump || command -v mysqldump'
```

Falls es fehlt, ist die Bereinigungsfunktion gesperrt. Bei diesem Alpine-basierten
CrazyMax-Image kann der Client für den Test installiert werden:

```bash
docker exec flarum2-flarum-1 apk add --no-cache mariadb-client
```

Diese zusätzliche Installation überlebt eine Container-Neuerstellung nicht. Für
spätere Produktion gehört sie in ein eigenes abgeleitetes Image; dafür den
Container jetzt nicht ungeplant neu erstellen. Die verwendeten DB-Zugangsdaten
stammen aus Flarums `config.php`. Der DB-Benutzer benötigt Exportrechte für
Tabellen, Trigger, Routinen und Events; ein fehlgeschlagener Export verhindert Purge.

## B. Flarum 1.8 als normale Installation

Diese Befehle sind gezielt für das vorhandene Flarum 1 unter `/var/www/flarum`
und den Benutzer `www-data`. Für eine normale Flarum-2-Installation die Pfade
und den PHP-FPM-Benutzer anpassen. Nicht mit dem Docker-Forum verwechseln.

### 1. Quelle außerhalb des Web-Verzeichnisses entpacken

ZIP zunächst nach `/tmp` übertragen:

```bash
install -d -m 755 /opt/el-choco
unzip -o /tmp/extension-uninstaller-0.1.0-rc.1.zip -d /opt/el-choco
cd /var/www/flarum
runuser -u www-data -- composer config repositories.el-choco-uninstaller '{"type":"path","url":"/opt/el-choco/extension-uninstaller","options":{"symlink":false,"versions":{"el-choco/extension-uninstaller":"0.1.0-rc.1"}}}'
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Laufende Paketaufträge vorher abschließen. Falls installiert, den nativen
Admin-Console-Dienst während der eigentlichen Installation stoppen:

```bash
systemctl stop el-choco-admin-console-flarum1.service
```

Nur nach erfolgreicher Prüfung:

```bash
cd /var/www/flarum
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
runuser -u www-data -- php flarum extension:enable el-choco-extension-uninstaller && \
runuser -u www-data -- php flarum cache:clear
```

Danach den tatsächlichen PHP-FPM-Dienst neu laden und den gegebenenfalls zuvor
gestoppten Admin-Console-Dienst wieder starten. PHP-CLI und FPM müssen zum Forum
passen. Nicht blind einen Dienstnamen einer anderen PHP-Version übernehmen.

### 2. Worker einrichten

Die mitgelieferte Datei verwendet `User=www-data`, `Group=www-data`,
`WorkingDirectory=/var/www/flarum` und `/usr/bin/php`. Diese Werte vorher prüfen.

```bash
cp /opt/el-choco/extension-uninstaller/resources/el-choco-uninstaller-flarum1.service /etc/systemd/system/el-choco-uninstaller-flarum1.service
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum1.service
systemctl status el-choco-uninstaller-flarum1.service --no-pager -l
```

Bei eigenen `site.php`-Pfaden dem Worker zusätzlich `--storage=/absoluter/pfad`
und `--public=/absoluter/pfad` geben. Sie müssen zu Flarums tatsächlichen Pfaden
passen. Für Purge muss `mariadb-dump` oder `mysqldump` auf dem Host installiert sein.

## C. Im Adminbereich benutzen

1. Seite mit Strg+F5 neu laden und die Erweiterung „Extension Uninstaller“ öffnen.
2. „Hintergrunddienst verbunden“ prüfen.
3. Eine entbehrliche Erweiterung wählen. Zum ersten Test **Nur deaktivieren**.
4. „Auswirkungen prüfen“ anklicken. Der Worker erstellt den Plan.
5. `DISABLE` zum Deaktivieren bzw. `DELETE` zum Deinstallieren eingeben und die Aktion bestätigen.
6. Ausgabe und Warnungen prüfen. Anschließend die Seite neu laden.
7. Danach „Daten behalten“, erneute Installation und erhaltene Daten testen.
8. Purge erst in einer separaten Datenbankkopie testen, einschließlich manuellem Restore.

Erweiterungseigene Uninstall-Hooks können auch Dateien löschen. Der SQL-Export
sichert keine Upload-Dateien; dafür die vorherige vollständige Dateisicherung behalten.

Alle üblichen Composer-Entfernungen laufen mit deaktivierten Scripts und Plugins.
Ein Bild- oder Paketmanager mit eigenen Composer-Nachbearbeitungen benötigt
gegebenenfalls eine gesonderte Integration; CrazyMax `extension sync` ist eingebaut.

## D. Sicherungen und Störungen

Privater Arbeitsordner: `storage/extension-uninstaller`. Dort liegen Aufträge,
Pläne und `backups/ID`. SQL-Dumps enthalten personenbezogene Daten. Der Ordner
darf **nicht über HTTP erreichbar** sein. Am besten liegt `storage` außerhalb des
öffentlichen Web-Verzeichnisses. Bei einem Webroot über dem gesamten Forum muss
der Webserver Zugriffe auf `/storage/` sperren; für Nginx beispielsweise in der
zuständigen Server-Konfiguration `location ^~ /storage/ { deny all; }`. Nicht
pauschal eine neue Server-Konfiguration über eine bestehende legen.

Abgeschlossene Auftragsprotokolle werden nach sieben Tagen bzw. oberhalb von
100 Einträgen beim nächsten Worker-Lauf bereinigt. Pläne und Paketsicherungen
werden in dieser ersten Version nicht automatisch gelöscht. Sicherungen erst
nach eigenem Aufbewahrungsplan entfernen. `database.sql` ist nicht verschlüsselt.

Wichtig: `composer.json`, `composer.lock` und die CrazyMax-Liste sichern keine
Paketdateien und keine Datenbank. Nach einem Fehler keine Lock-Dateien allein
zurückkopieren und „fertig“ annehmen. `manifest.json` beschreibt einen eventuell
vorhandenen SQL-Export samt SHA-256; ein Restore wurde dadurch nicht getestet.

Bei „Prüfung erforderlich“ zuerst Prozesse, Status und Sicherung im Terminal
prüfen. Keine laufenden Composer-Prozesse blind beenden und keine Aufträge
automatisch wiederholen. Dienstprotokoll für Docker:

```bash
journalctl -u el-choco-uninstaller-flarum2.service -n 80 --no-pager
```

Nach manueller Wiederherstellung und nur wenn kein Kindprozess mehr läuft den
Worker stoppen, den betroffenen Auftrag bestätigen und den Dienst neu starten:

```bash
systemctl stop el-choco-uninstaller-flarum2.service
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/opt/flarum --acknowledge=AUFTRAGS-ID
systemctl start el-choco-uninstaller-flarum2.service
```

`AUFTRAGS-ID` ersetzen. Ein Acknowledge repariert nichts; es bestätigt nur die
bereits erfolgte manuelle Prüfung. Für die native Installation entsprechend
deren Dienst und `runuser -u www-data -- php ... --base=/var/www/flarum` verwenden.

Vor Updates des Deinstallierers Aufträge abschließen und seinen Worker stoppen.
Nach dem Update wieder starten und PHP-FPM bei altem OPcache neu laden. Für eine
spätere Packagist-Installation muss die lokale Path-Quelle gesondert entfernt
werden, damit sie eine neuere veröffentlichte Version nicht übersteuert.
