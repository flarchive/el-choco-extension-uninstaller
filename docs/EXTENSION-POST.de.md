# Extension Uninstaller — Erweiterungen mit Vorschau deinstallieren

**Extension Uninstaller** ergänzt Flarum um eine eigene Verwaltungsoberfläche zum Deaktivieren und Deinstallieren von Erweiterungen. Geplante Änderungen werden vorab geprüft. Anschließend lässt sich festlegen, ob zugehörige Daten erhalten bleiben oder bereinigt werden sollen.

Unterstützt werden **Flarum 1.8 und 2.x**, sowohl als normale Installation als auch im CrazyMax-Docker-Container. **Die Admin Console wird nicht benötigt.**

![Extension Uninstaller im Adminbereich](https://raw.githubusercontent.com/el-choco/extension-uninstaller/main/docs/preview.png)

## Drei Möglichkeiten

| Aktion | Paketdateien | Erweiterungsdaten |
|---|---|---|
| Nur deaktivieren | Bleiben installiert | Keine Datenbankbereinigung |
| Deinstallieren und Daten behalten | Werden über Composer entfernt | Kein Purge; spätere Neuinstallation möglich |
| Deinstallieren und Daten bereinigen | Werden über Composer entfernt | Zuerst Datenbankexport, danach Flarums Bereinigung |

Zum Deaktivieren wird **`DISABLE`**, zum Deinstallieren **`DELETE`** eingegeben. Für die Datenbereinigung ist eine zusätzliche Checkbox erforderlich.

## Funktionen

- Installierte Erweiterungen durchsuchen und Aktivierung sowie Abhängigkeiten anzeigen.
- Deinstallationspläne in einem getrennten Arbeitsverzeichnis vorbereiten.
- Pläne ablehnen, die andere Pakete installieren oder aktualisieren bzw. weitere Erweiterungen oder geschützte Pakete entfernen würden.
- Ungenutzte Bibliotheken anzeigen, die Composer zusammen mit der ausgewählten Erweiterung entfernen möchte.
- Bestätigte Aufträge über einen eigenständigen Hintergrunddienst ausführen, mit Status, Ausgabe und Paketsicherung.
- Vor der Datenbereinigung einen erfolgreichen SQL-Export verlangen.
- Die CrazyMax-Erweiterungsliste nach erfolgreicher Paketentfernung synchronisieren.
- Deutsche und englische Oberfläche.

**Zur Datenbereinigung:** Flarum verwendet die Rückwärtsmigrationen und Uninstall-Hooks der jeweiligen Erweiterung. Einstellungen, Uploads oder andere Reste können zurückbleiben; eigene Hooks können auch Dateien löschen. Der Deinstallierer errät keine Tabellennamen und verspricht keine vollständige Restebereinigung. Ein SQL-Export ersetzt keine vollständige Dateisicherung. Es gibt keinen automatischen Datenbank-Rollback.

## Voraussetzungen

- Flarum 1.8 oder 2.x mit einer dazu passenden PHP-Version.
- Linux, Composer 2.8+, MySQL/MariaDB und das normale Composer-Verzeichnis `vendor`.
- Terminalzugriff zur Einrichtung eines Hintergrunddienstes unter dem Nicht-root-Benutzer des Forums.
- Für Datenbereinigung: InnoDB-Tabellen sowie `mariadb-dump` oder `mysqldump` in der Worker-Umgebung und ausreichende Exportrechte.

**Aktuelle Version: `0.1.0-rc.2`.** Dies ist ein Release Candidate. Rückmeldungen sind willkommen; Datenbereinigung und Wiederherstellung bitte zunächst an einer separaten Forums-/Datenbankkopie prüfen.

## Installation: normales Flarum

Die Befehle im Flarum-Verzeichnis **als Forum-/PHP-Benutzer** ausführen, nicht als root:

```bash
composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction
php flarum extension:enable el-choco-extension-uninstaller
php flarum cache:clear
```

Auch hier ist ein Worker erforderlich. Eine native systemd-Vorlage liegt unter `resources/el-choco-uninstaller-flarum1.service`. Nach Anpassung von Pfaden und Benutzer ist sie für beide unterstützten Flarum-Versionen geeignet. Die Einrichtung steht in der [Installationsanleitung](https://github.com/el-choco/extension-uninstaller/blob/main/INSTALL-DE.md).

Für eine neue native Installation unter `/var/www/flarum` lässt sich der Dienst mit folgenden Befehlen **als root** einrichten. Vor dem Aktivieren in der Vorlage Forum-Pfad, PHP-Pfad, Benutzer und Gruppe an die eigene Installation anpassen:

```bash
cp /var/www/flarum/vendor/el-choco/extension-uninstaller/resources/el-choco-uninstaller-flarum1.service /etc/systemd/system/el-choco-uninstaller-native.service
nano /etc/systemd/system/el-choco-uninstaller-native.service
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-native.service
systemctl status el-choco-uninstaller-native.service --no-pager -l
```

## Installation: CrazyMax Docker

Das folgende Beispiel verwendet den Container **`flarum2-flarum-1`**, das Forum-Verzeichnis **`/opt/flarum`** und den Container-Benutzer **`flarum`**. Diese Angaben bei Bedarf anpassen. Die Befehle auf dem Docker-Host ausführen.

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum extension:enable el-choco-extension-uninstaller && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Die Erweiterungsliste nur nach erfolgreichem Composer-Vorgang synchronisieren. Falls Web-PHP noch alten Code verwendet, PHP-FPM neu laden:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
```

### Hintergrunddienst erstellen

Die folgenden Befehle **als root auf dem Docker-Host** ausführen. Zuvor Containername und den Pfad `/usr/bin/docker` prüfen. Die PHP-Befehle im Container laufen weiterhin als `flarum`.

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

systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

Der Worker prüft neue Aufträge ungefähr jede Sekunde. Ein zusätzlicher Minuten-Cron ist nicht erforderlich. Vorhandene Admin-Console-Dienste bleiben eigenständig.

Das Dienstprotokoll lässt sich so anzeigen:

```bash
journalctl -u el-choco-uninstaller-flarum2.service -n 80 --no-pager
```

Für die Bereinigung muss das Dump-Programm **im Flarum-Container** verfügbar sein:

```bash
docker exec flarum2-flarum-1 sh -c 'command -v mariadb-dump || command -v mysqldump'
```

Fehlt es, wird die Datenbereinigung blockiert. Deaktivieren und Deinstallieren mit erhaltenen Daten benötigen dieses Programm nicht. Die Installationsanleitung beschreibt das Alpine-Clientpaket und die dauerhafte Bereitstellung.

## Verwendung

1. Im Adminbereich **Extension Uninstaller** öffnen und die Worker-Verbindung prüfen.
2. Erweiterung und gewünschte Aktion auswählen.
3. Den Plan erstellen und prüfen.
4. `DISABLE` oder `DELETE` eingeben und bestätigen. Die Bereinigung benötigt ihre zusätzliche Zustimmung.
5. Nach Abschluss die Ausgabe prüfen und das Forum neu laden.

Aufträge, Pläne und Sicherungen liegen privat unter `storage/extension-uninstaller`. Dieses Verzeichnis darf nicht über HTTP erreichbar sein. SQL-Sicherungen enthalten sensible Daten.

Vor Updates laufende Aufträge abschließen und den Uninstaller-Worker stoppen. Als Forum-Benutzer aktualisieren, CrazyMax nur nach Erfolg synchronisieren, den Cache leeren und den Worker neu starten. Frühere lokale Testinstallationen müssen vor dem Packagist-Wechsel von ihrer Path-Quelle umgestellt werden.

## Links und Rückmeldungen

- [Packagist](https://packagist.org/packages/el-choco/extension-uninstaller)
- [GitHub](https://github.com/el-choco/extension-uninstaller)
- [Installationsanleitung](https://github.com/el-choco/extension-uninstaller/blob/main/INSTALL-DE.md)
- [Fehler melden](https://github.com/el-choco/extension-uninstaller/issues)

Bei Fehlermeldungen bitte Flarum-/PHP-Version, Installationsart und die betreffende Auftragsausgabe angeben. Zugangsdaten und personenbezogene Informationen vorher entfernen; keine Datenbank-Dumps anhängen.

**Lizenz: MIT.**
