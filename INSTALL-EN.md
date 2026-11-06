# Installation from Packagist

For `el-choco/extension-uninstaller` version `0.1.0-rc.2`, once its release tag is available on Packagist.
Choose **A** for a normal installation without Docker, or **B** for CrazyMax Docker.
The Admin Console is optional and has its own independent service.

**No ZIP download or local Composer repository is required.**
The worker is part of the installed package:

```text
YOUR_FLARUM_DIRECTORY/vendor/el-choco/extension-uninstaller/bin/worker.php
```

Flarum has no mandatory installation path. Use the directory containing your
`flarum`, `config.php` and `vendor` files. The native example below uses
`/var/www/flarum`. No separate source directory is needed.

Requirements: Linux with systemd for these examples, Flarum 1.8 or 2.x, Composer
2.8+, a compatible PHP CLI with PDO MySQL, MySQL/MariaDB and a standard Composer
`vendor` directory. The worker and web PHP must use the same non-root user.

## A. Normal installation — without Docker

### 1. Install the extension

If RC2 is already installed, continue with **step 2**. For RC1 or older versions, use [UPDATE-EN.md](UPDATE-EN.md) first.

These commands assume a root shell, a forum at `/var/www/flarum` and web user
`www-data`. Composer and Flarum themselves are run as `www-data`:

```bash
cd /var/www/flarum
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
runuser -u www-data -- php flarum extension:enable el-choco-extension-uninstaller && \
runuser -u www-data -- php flarum cache:clear
```

Wait for running package jobs to finish before installing. If any command fails,
resolve it before continuing. Do not recreate the forum or delete its extension list.

### 2. Check the installed worker

```bash
ls -l /var/www/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php
command -v php
```

The first command must show the worker file. If it does not, check your actual
Flarum directory and whether the Composer installation succeeded. Do not create
a separate source directory to compensate for a missing installed package.

### 3. Create the systemd service

Run this as root. **Replace every `/var/www/flarum` if your forum is elsewhere.**
Also replace `www-data` in both User and Group if your PHP-FPM worker uses another
account. `/usr/bin/php` must be the CLI PHP version used for this forum.

If you already created an uninstaller service with another name, correct that
service instead of enabling a second worker for the same forum.

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

### 4. Start the worker

```bash
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-native.service
systemctl status el-choco-uninstaller-native.service --no-pager -l
```

The service should report **active (running)**. Then hard-refresh the admin page
with Ctrl+F5 and open Extension Uninstaller. It should show the background service
as connected. No additional cron entry is required.

If it does not start:

```bash
journalctl -u el-choco-uninstaller-native.service -n 80 --no-pager
```

For custom `site.php` paths, add matching `--storage=/absolute/path` and
`--public=/absolute/path` options to both ExecStart and ExecStop.

## B. CrazyMax Docker installation

Run all commands on the Docker host. The example uses:

| Setting | Example |
|---|---|
| Container | `flarum2-flarum-1` |
| Forum directory inside the container | `/opt/flarum` |
| PHP user inside the container | `flarum` |

**Here `/opt/flarum` is CrazyMax's internal container path. It is not a directory
you need to create on the host, and it is not used by the native instructions.**
Adjust the container name and internal path if your image uses different values.

### 1. Install the extension

If RC2 is already installed, continue with **step 2**. For older versions, see [UPDATE-EN.md](UPDATE-EN.md). Finish active package jobs first.

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum extension:enable el-choco-extension-uninstaller && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Only run `extension sync` after Composer succeeds. This setup needs no additional
mount and no container recreation. If web PHP still uses old code, reload it:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
```

### 2. Check the installed worker

```bash
docker exec flarum2-flarum-1 ls -l /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php
```

### 3. Create the Docker worker service

Run this as root on the host. Check that Docker is installed at `/usr/bin/docker`.
Do not add `User=flarum` to the host service: `flarum` is the container account,
selected explicitly by `docker exec --user flarum`.

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

### 4. Start the Docker worker

```bash
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

Then hard-refresh the admin page. For errors:

```bash
journalctl -u el-choco-uninstaller-flarum2.service -n 80 --no-pager
```

The worker checks for jobs approximately every second. The Admin Console service,
if installed, remains separate. Neither example requires a minute-based cron.

## C. Optional database cleanup

Disabling and keeping data do not require a dump utility. Cleanup requires
`mariadb-dump` or `mysqldump` in the environment where the worker actually runs.

Native:

```bash
command -v mariadb-dump || command -v mysqldump
```

Docker:

```bash
docker exec flarum2-flarum-1 sh -c 'command -v mariadb-dump || command -v mysqldump'
```

If the utility is missing, cleanup is blocked. For the Alpine-based CrazyMax image,
`docker exec flarum2-flarum-1 apk add --no-cache mariadb-client` can provide it for
testing. Such an installation does not survive container recreation; use a derived
image for persistent provisioning. Do not recreate an existing container unexpectedly.

Cleanup requires InnoDB base tables and database-export permissions for tables,
triggers, routines and events. Flarum runs the extension's own rollback migrations
and uninstall hooks. Some data may remain; custom hooks can also delete files.
Keep a full file backup and test SQL recovery on a separate database copy.

## D. Storage, updates and interrupted jobs

Private runtime files live in `storage/extension-uninstaller`, with snapshots in
`backups/ID`. This directory must not be accessible over HTTP. SQL exports contain
sensitive data and are not encrypted. The automatic package snapshot is not a
complete forum backup and does not include installed package code or uploaded files.

Before updates, finish jobs and stop the worker service you configured above.
Update as the forum user, synchronize CrazyMax only after success, clear the cache,
then start that same worker service again. Reload web PHP if OPcache is stale.
If migrating from an old local test installation, its path repository must be
handled separately; it is not required for a fresh Packagist installation.

An interrupted job blocks further work. Inspect processes, package state and
backups before recovering manually. Do not automatically retry or blindly kill
Composer. Acknowledgment is only allowed after manual inspection, with no child
process still running; it is not a repair command.

Native example, replacing `JOB_ID`:

```bash
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
runuser -u www-data -- /usr/bin/php vendor/el-choco/extension-uninstaller/bin/worker.php --base=/var/www/flarum --acknowledge=JOB_ID
systemctl start el-choco-uninstaller-native.service
```

Docker example:

```bash
systemctl stop el-choco-uninstaller-flarum2.service
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/opt/flarum --acknowledge=JOB_ID
systemctl start el-choco-uninstaller-flarum2.service
```

Completed logs expire after seven days or above 100 records on a later worker pass.
Plans and backups are not automatically deleted in this version. Review them under
your own retention policy. Successful export does not prove a successful restore.

## E. Local Composer package repositories

URLs of `type: path` repositories in the forum's `composer.json` must be absolute
for the isolated preview. Keep the repository itself. For a native forum located
at `/var/www/flarum`, an example is:

```json
{"type":"path","url":"/var/www/flarum/workbench/*"}
```

This replaces only the relative URL `workbench/*`. Back up `composer.json` privately
before editing it. Inside a container, use a path from that container's filesystem,
such as `/opt/flarum/workbench/*`.

An unchanged local package can still have a relative URL in its lock metadata.
RC2 accepts a preview rewrite only when both paths resolve to the same existing
directory and all other package metadata matches. Other changes remain blocked.
