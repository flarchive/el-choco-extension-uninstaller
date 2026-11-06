# Extension Uninstaller — reviewed removals with optional data cleanup

**Extension Uninstaller** adds a dedicated admin interface for disabling and removing Flarum extensions. Review the proposed changes, choose what should happen to the extension's data, and explicitly confirm the action before it runs.

It supports **Flarum 1.8 and 2.x**, including native installations and CrazyMax Docker deployments. **Admin Console is not required.**

![Extension Uninstaller admin interface, shown in German](https://raw.githubusercontent.com/el-choco/extension-uninstaller/main/docs/preview.png)

## Three actions

| Action | Package files | Extension data |
|---|---|---|
| Disable only | Kept | No database cleanup |
| Uninstall and keep data | Removed through Composer | No purge, allowing a later reinstall |
| Uninstall and clean up data | Removed through Composer | Database export first, then Flarum's purge |

Type **`DISABLE`** to confirm disabling or **`DELETE`** to confirm uninstalling. Database cleanup requires an additional checkbox.

## Features

- Search installed extensions and inspect their activation state and dependencies.
- Prepare removal plans in a separate working directory before changing the forum.
- Reject plans that update/install other packages or remove another extension or a protected package.
- Show unused libraries that Composer proposes to remove alongside the selected extension.
- Execute confirmed jobs through an independent background worker, with status, output and package snapshots.
- Require a successful SQL export before starting database cleanup.
- Synchronize the CrazyMax extension list after successful package removal.
- German and English interface.

**About data cleanup:** Flarum uses the extension's own rollback migrations and uninstall hooks. Some settings, uploads or other data may remain; custom hooks may also delete files. The uninstaller does not guess table names or promise universal leftover removal. A SQL export does not replace a full file backup, and there is no automatic database rollback.

## Requirements

- Flarum 1.8 or 2.x, with a compatible PHP version.
- Linux, Composer 2.8+, MySQL/MariaDB and the standard Composer `vendor` directory.
- Terminal access to configure a background worker running as the forum's non-root PHP user.
- For data cleanup: InnoDB tables and `mariadb-dump` or `mysqldump` in the worker environment, with sufficient database-export permissions.

**Current release: `0.1.0-rc.2`.** This is a release candidate. Feedback is welcome; test database cleanup and recovery on a separate forum/database copy first.

## Installation: native Flarum

Run from your Flarum directory **as the forum/PHP user**, not root:

```bash
composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction
php flarum extension:enable el-choco-extension-uninstaller
php flarum cache:clear
```

A background worker is required here as well. A native systemd template is included under `resources/el-choco-uninstaller-flarum1.service`; it works for either supported Flarum version after adjusting its paths and user. See the [installation guide](https://github.com/el-choco/extension-uninstaller/blob/main/INSTALL-EN.md) for setup.

For a fresh native installation at `/var/www/flarum`, run the service setup below **as root**. Before enabling it, edit the template to match the forum path, PHP binary, user and group:

```bash
cp /var/www/flarum/vendor/el-choco/extension-uninstaller/resources/el-choco-uninstaller-flarum1.service /etc/systemd/system/el-choco-uninstaller-native.service
nano /etc/systemd/system/el-choco-uninstaller-native.service
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-native.service
systemctl status el-choco-uninstaller-native.service --no-pager -l
```

## Installation: CrazyMax Docker

The following example uses container **`flarum2-flarum-1`**, forum directory **`/opt/flarum`** and container user **`flarum`**. Adjust these values to your setup. Run the commands on the Docker host.

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum extension:enable el-choco-extension-uninstaller && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Only synchronize the extension list after Composer succeeds. If web PHP retains old code, reload PHP-FPM:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
```

### Create the background service

Run the following **as root on the Docker host**, after checking the container name and `/usr/bin/docker` path. PHP commands inside the container still run as `flarum`.

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

The worker checks new jobs approximately every second. No additional minute-based cron entry is needed. Existing Admin Console services remain separate.

To inspect worker logs:

```bash
journalctl -u el-choco-uninstaller-flarum2.service -n 80 --no-pager
```

For cleanup, check that a dump utility exists **inside the Flarum container**:

```bash
docker exec flarum2-flarum-1 sh -c 'command -v mariadb-dump || command -v mysqldump'
```

If it is missing, cleanup is blocked. Disabling and keeping data do not require this utility. The installation guide covers the Alpine client package and its persistence requirements.

## Using the extension

1. Open **Extension Uninstaller** in the admin area and check that the worker is connected.
2. Select an extension and choose an action.
3. Prepare and review the plan.
4. Enter `DISABLE` or `DELETE`, then confirm. Cleanup requires its additional consent.
5. Review the output and reload the forum after completion.

Jobs, plans and snapshots are stored privately under `storage/extension-uninstaller`. Keep this directory inaccessible over HTTP. SQL backups contain sensitive data.

Before updating, finish active jobs and stop the uninstaller worker. Update as the forum user, synchronize CrazyMax only after success, clear the cache and restart the worker. Local test installations must migrate away from their path repository before using Packagist releases.

## Links and feedback

- [Packagist](https://packagist.org/packages/el-choco/extension-uninstaller)
- [GitHub](https://github.com/el-choco/extension-uninstaller)
- [Installation guide](https://github.com/el-choco/extension-uninstaller/blob/main/INSTALL-EN.md)
- [Report an issue](https://github.com/el-choco/extension-uninstaller/issues)

For bug reports, please include your Flarum/PHP versions, installation type and the relevant job output. Remove credentials and personal information; do not attach database dumps.

**License: MIT.**
