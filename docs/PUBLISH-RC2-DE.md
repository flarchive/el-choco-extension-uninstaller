# RC2 auf GitHub und Packagist veröffentlichen

Paket: `el-choco/extension-uninstaller` · Tag: `v0.1.0-rc.2`

## 1. Dateien auf GitHub aktualisieren

Das Archiv `extension-uninstaller-0.1.0-rc.2.zip` entpacken und den Inhalt seines
Ordners `extension-uninstaller` im vorhandenen Repository
<https://github.com/el-choco/extension-uninstaller> aktualisieren.

Die Dateien gehören direkt in die Repository-Wurzel. Keinen zusätzlichen Ordner
`extension-uninstaller` um alles legen. Die gebauten Dateien unter `js/dist`
müssen mit hochgeladen werden. Der lokale Projektordner `dist` mit früheren
Release-Archiven gehört nicht ins Repository.

Die Dateien auf dem Hauptzweig committen. Der bestehende RC1-Tag bleibt unverändert.

## 2. Neues Release erstellen

Unter **Releases → Draft a new release** einen neuen Tag `v0.1.0-rc.2` auf dem
gerade aktualisierten Commit erstellen. Als Titel zum Beispiel
**Extension Uninstaller 0.1.0-rc.2** verwenden und **Pre-release** aktivieren.

Den Text aus [RELEASE-RC2-EN.md](RELEASE-RC2-EN.md) oder
[RELEASE-RC2-DE.md](RELEASE-RC2-DE.md) verwenden. ZIP und `.sha256`-Datei können
zusätzlich als Release-Dateien angehängt werden.

Ein angehängtes ZIP allein aktualisiert Composer nicht. Entscheidend sind die
Dateien des getaggten Repository-Commits.

## 3. Packagist prüfen

Der bestehende Webhook sollte den neuen Tag übernehmen. Auf
<https://packagist.org/packages/el-choco/extension-uninstaller> prüfen, ob
`v0.1.0-rc.2` erscheint. Falls nötig **Update** auslösen und die letzte
Webhook-Zustellung auf GitHub kontrollieren. Kein neuer Webhook erforderlich.

## 4. Zielsysteme aktualisieren

Erst wenn RC2 auf Packagist sichtbar ist, [UPDATE-DE.md](../UPDATE-DE.md) verwenden.
Normale Installation und Docker haben dort eigene Abschnitte. Danach Version,
Worker-Verbindung und eine neue Vorschau prüfen.

RC2 bleibt ein Release Candidate. Die offenen Prüfungen stehen im
[Testbericht](TEST-REPORT.md).
