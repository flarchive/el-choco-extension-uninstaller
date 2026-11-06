# Installation über Packagist

Für `el-choco/extension-uninstaller` in Version `0.1.0-rc.2`, sobald der Release-Tag auf Packagist verfügbar ist.
**Abschnitt A** gilt für normale Installationen ohne Docker, **Abschnitt B** für
CrazyMax Docker. Die Admin Console ist optional und hat einen eigenen Dienst.

**Es wird kein ZIP und kein lokales Composer-Repository benötigt.**
Der Worker gehört zum installierten Paket:

```text
DEIN_FLARUM_VERZEICHNIS/vendor/el-choco/extension-uninstaller/bin/worker.php
```

Flarum hat keinen zwingenden Installationspfad. Gemeint ist das Verzeichnis mit
den Dateien `flarum`, `config.php` und dem Ordner `vendor`. Das Beispiel für eine
normale Installation verwendet `/var/www/flarum`. Es wird kein zusätzliches
Quellverzeichnis benötigt.

Voraussetzungen: Linux mit systemd für diese Beispiele, Flarum 1.8 oder 2.x,
Composer 2.8+, passende PHP-CLI mit PDO MySQL, MySQL/MariaDB und das normale
Composer-Verzeichnis `vendor`. Worker und Web-PHP müssen denselben Nicht-root-
Benutzer verwenden.

## A. Normale Installation — ohne Docker

### 1. Erweiterung installieren

Ist bereits RC2 installiert, direkt mit **Schritt 2** fortfahren. Bei RC1 oder älteren Versionen zuerst [UPDATE-DE.md](UPDATE-DE.md) verwenden.

Die Befehle sind für eine root-Konsole, das Forum `/var/www/flarum` und den
PHP-Benutzer `www-data` ausgelegt. Composer und Flarum selbst laufen als `www-data`:

```bash
cd /var/www/flarum
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
runuser -u www-data -- php flarum extension:enable el-choco-extension-uninstaller && \
runuser -u www-data -- php flarum cache:clear
```

Laufende Paketaufträge vorher abschließen. Bei einem Fehler zuerst die Ursache
klären. Weder das Forum neu erstellen noch eine Erweiterungsliste löschen.

### 2. Installierten Worker prüfen

```bash
ls -l /var/www/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php
command -v php
```

Der erste Befehl muss die Worker-Datei anzeigen. Fehlt sie, den tatsächlichen
Forum-Pfad und die erfolgreiche Composer-Installation prüfen. Kein zusätzliches
Quellverzeichnis als Ersatz für ein fehlendes installiertes Paket anlegen.

### 3. systemd-Dienst erstellen

Als root ausführen. **Wenn dein Forum woanders liegt, jedes `/var/www/flarum`
ersetzen.** Falls PHP-FPM unter einem anderen Benutzer läuft, auch `User` und
`Group` ändern. `/usr/bin/php` muss zur PHP-CLI dieses Forums passen.

Existiert bereits ein Uninstaller-Dienst unter einem anderen Namen, diesen
korrigieren und keinen zweiten Worker für dasselbe Forum aktivieren.

```bash
cat > /etc/systemd/system/el-choco-uninstaller-native.service <<'EOF'
[Unit]
Description=el-choco Extension Uninstaller (Flarum native)
After=network.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/flarum
ExecStart=/usr/bin/php /var/www/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/var/www/flarum --watch
ExecStop=-/usr/bin/php /var/www/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/var/www/flarum --stop
Restart=always
RestartSec=5
TimeoutStopSec=infinity

[Install]
WantedBy=multi-user.target
EOF
```

### 4. Worker starten

```bash
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-native.service
systemctl status el-choco-uninstaller-native.service --no-pager -l
```

Der Dienst sollte **active (running)** melden. Anschließend im Browser Strg+F5
drücken und Extension Uninstaller öffnen. Der Hintergrunddienst sollte als
verbunden angezeigt werden. Es wird kein zusätzlicher Cron-Eintrag benötigt.

Startet der Dienst nicht:

```bash
journalctl -u el-choco-uninstaller-native.service -n 80 --no-pager
```

Bei eigenen `site.php`-Pfaden passende Optionen `--storage=/absoluter/pfad` und
`--public=/absoluter/pfad` in ExecStart und ExecStop ergänzen.

## B. CrazyMax Docker

Alle Befehle auf dem Docker-Host ausführen. Das Beispiel verwendet:

| Einstellung | Beispiel |
|---|---|
| Container | `flarum2-flarum-1` |
| Forum-Pfad innerhalb des Containers | `/opt/flarum` |
| PHP-Benutzer innerhalb des Containers | `flarum` |

**`/opt/flarum` ist hier der interne Pfad des CrazyMax-Containers. Auf dem Host
muss dafür kein Verzeichnis erstellt werden. Die normale Installation aus
Abschnitt A verwendet diesen Pfad nicht.** Containername und internen Pfad bei
anderen Images entsprechend anpassen.

### 1. Erweiterung installieren

Bei bereits installiertem RC2 direkt zu **Schritt 2**. Für ältere Versionen siehe [UPDATE-DE.md](UPDATE-DE.md). Laufende
Paketaufträge vorher abschließen.

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum extension:enable el-choco-extension-uninstaller && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

`extension sync` nur nach erfolgreichem Composer-Vorgang ausführen. Es werden
kein zusätzliches Volume und keine Container-Neuerstellung benötigt. Falls
Web-PHP noch alten Code verwendet:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
```

### 2. Installierten Worker prüfen

```bash
docker exec flarum2-flarum-1 ls -l /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php
```

### 3. Docker-Worker-Dienst erstellen

Als root auf dem Host ausführen. Docker muss hier unter `/usr/bin/docker` liegen.
Kein `User=flarum` in diesen Host-Dienst eintragen: `flarum` ist der Container-
Benutzer und wird bereits durch `docker exec --user flarum` ausgewählt.

```bash
cat > /etc/systemd/system/el-choco-uninstaller-flarum2.service <<'EOF'
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
EOF
```

### 4. Docker-Worker starten

```bash
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

Danach den Adminbereich neu laden. Bei Fehlern:

```bash
journalctl -u el-choco-uninstaller-flarum2.service -n 80 --no-pager
```

Der Worker prüft neue Aufträge ungefähr jede Sekunde. Ein vorhandener
Admin-Console-Dienst bleibt getrennt. Kein zusätzlicher Minuten-Cron erforderlich.

## C. Optionale Datenbereinigung

Deaktivieren und Daten behalten benötigen kein Dump-Programm. Die Bereinigung
benötigt `mariadb-dump` oder `mysqldump` dort, wo der Worker tatsächlich läuft.

Normale Installation:

```bash
command -v mariadb-dump || command -v mysqldump
```

Docker:

```bash
docker exec flarum2-flarum-1 sh -c 'command -v mariadb-dump || command -v mysqldump'
```

Fehlt das Programm, wird die Bereinigung blockiert. Beim Alpine-basierten
CrazyMax-Image kann `docker exec flarum2-flarum-1 apk add --no-cache mariadb-client`
es für einen Test bereitstellen. Das überlebt keine Container-Neuerstellung;
für dauerhafte Bereitstellung ein abgeleitetes Image verwenden. Den bestehenden
Container dafür nicht ungeplant neu erstellen.

Die Bereinigung setzt InnoDB-Basistabellen und Exportrechte für Tabellen, Trigger,
Routinen und Events voraus. Flarum verwendet die Rückwärtsmigrationen und
Uninstall-Hooks der Erweiterung. Reste können bleiben; eigene Hooks können auch
Dateien löschen. Eine vollständige Dateisicherung behalten und SQL-Wiederherstellung
zunächst an einer separaten Datenbankkopie prüfen.

## D. Speicherung, Updates und unterbrochene Aufträge

Private Laufzeitdateien liegen unter `storage/extension-uninstaller`, Sicherungen
unter `backups/ID`. Dieser Ordner darf nicht über HTTP erreichbar sein. SQL-Exporte
enthalten sensible Daten und sind nicht verschlüsselt. Die automatische
Paketsicherung ist keine vollständige Forumsicherung und enthält weder installierten
Paketcode noch Upload-Dateien.

Vor Updates Aufträge abschließen und den oben eingerichteten Worker-Dienst
stoppen. Als Forum-Benutzer aktualisieren, CrazyMax nur nach Erfolg synchronisieren,
den Cache leeren und anschließend denselben Dienst wieder starten. Web-PHP bei
altem OPcache neu laden. Bei früheren lokalen Testinstallationen muss deren
Path-Repository separat behandelt werden; für neue Packagist-Installationen
ist es nicht erforderlich.

Unterbrochene Aufträge sperren weitere Änderungen. Prozesse, Paketstand und
Sicherungen prüfen und gegebenenfalls manuell wiederherstellen. Composer nicht
blind beenden und Aufträge nicht automatisch wiederholen. Acknowledge erst nach
manueller Prüfung und wenn kein Kindprozess mehr läuft; es repariert nichts.

Normale Installation, mit ersetzter `AUFTRAGS-ID`:

```bash
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
runuser -u www-data -- /usr/bin/php vendor/el-choco/extension-uninstaller/bin/worker.php --base=/var/www/flarum --acknowledge=AUFTRAGS-ID
systemctl start el-choco-uninstaller-native.service
```

Docker:

```bash
systemctl stop el-choco-uninstaller-flarum2.service
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/opt/flarum --acknowledge=AUFTRAGS-ID
systemctl start el-choco-uninstaller-flarum2.service
```

Abgeschlossene Protokolle werden nach sieben Tagen bzw. oberhalb von 100 Einträgen
beim nächsten Worker-Lauf bereinigt. Pläne und Sicherungen in dieser Version selbst
nach Aufbewahrungsplan prüfen. Ein erfolgreicher Export ist kein getesteter Restore.

## E. Lokale Composer-Paketquellen

URLs von `type: path`-Repositories in der Forum-`composer.json` müssen für die
isolierte Vorschau absolute Pfade sein. Die Quelle selbst bleibt bestehen.
Beispiel für ein natives Forum unter `/var/www/flarum`:

```json
{"type":"path","url":"/var/www/flarum/workbench/*"}
```

Das ersetzt dort nur den relativen URL-Wert `workbench/*`. Vor einer manuellen
Änderung die `composer.json` privat sichern. Im Container muss eine solche URL
auf dessen Dateisystem zeigen, etwa `/opt/flarum/workbench/*`.

RC2 berücksichtigt, dass die Lock-Datei eines unveränderten lokalen Pakets
trotzdem eine relative URL enthalten kann. Wird sie während der Vorschau
umgeschrieben, akzeptiert RC2 dies nur bei derselben nachgewiesenen Quelle und
identischen übrigen Paketdaten. Andere Änderungen bleiben gesperrt.
