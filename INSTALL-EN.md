# Installation and first test

Version `0.1.0-rc.1` is a release candidate; final live validation is still pending.
The extension is standalone: Admin Console is optional. If both are present,
their workers share the existing Admin Console execution lock.

Requirements: Linux, Flarum 1.8 or 2.x, Composer 2.8+, standard `vendor` directory,
MySQL/MariaDB, CLI PHP matching the forum, and the same non-root UID for web PHP
and the worker. The CLI must provide PDO MySQL. No shell commands can be entered
through this interface.

## A. Existing CrazyMax Flarum 2 container

These commands run on the Docker host, using:

- Host project: `/mnt/wdblue/flarum2`
- Container: `flarum2-flarum-1`
- Forum: `/opt/flarum`
- Container user: `flarum`
- Existing mount: host `data` to container `/data`

No extra mount or container recreation is needed. Adjust names/paths for other
setups. Do not replace the existing Admin Console service.

### 1. Transfer and extract

Upload `extension-uninstaller-0.1.0-rc.1.zip` to `/mnt/wdblue/flarum2`:

```bash
unzip -o /mnt/wdblue/flarum2/extension-uninstaller-0.1.0-rc.1.zip -d /mnt/wdblue/flarum2/data
docker exec flarum2-flarum-1 ls -l /data/extension-uninstaller/composer.json
```

The ZIP contains the `extension-uninstaller` directory. Extracted source files
must be readable by the forum user. Do not put source files in
`storage/extension-uninstaller`, which is reserved for private runtime data.

### 2. Register the local Composer source

Finish active Admin Console jobs. If installed, briefly stop its worker:

```bash
systemctl stop el-choco-admin-console.service
```

Register an absolute path repository that installs a physical copy rather than
a symlink. Do not delete existing repositories or the extension list:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer config repositories.el-choco-uninstaller '{"type":"path","url":"/data/extension-uninstaller","options":{"symlink":false,"versions":{"el-choco/extension-uninstaller":"0.1.0-rc.1"}}}'
```

Preview installation first:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Stop if unrelated updates/removals appear. After a successful preview:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum extension:enable el-choco-extension-uninstaller && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Only synchronize the extension list after Composer succeeds. Then reload web PHP
and restart the previously stopped Admin Console service, if applicable:

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
systemctl start el-choco-admin-console.service
```

### 3. Install the independent worker

Review `resources/el-choco-uninstaller-flarum2.service`, especially the container
name and `/usr/bin/docker` path, then copy it:

```bash
cp /mnt/wdblue/flarum2/data/extension-uninstaller/resources/el-choco-uninstaller-flarum2.service /etc/systemd/system/el-choco-uninstaller-flarum2.service
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

The service runs `bin/worker.php --base=/opt/flarum --watch` as container user
`flarum`. New jobs are checked approximately every second; no minute-based cron
is required. The service deliberately waits for an active operation to finish
when stopped. Do not forcibly kill Composer merely because stopping takes time.

### 4. Optional database-cleanup prerequisites

Disable-only and keep-data removal do not require a dump utility. Purge requires
`mariadb-dump` or `mysqldump` **inside the Flarum worker container**:

```bash
docker exec flarum2-flarum-1 sh -c 'command -v mariadb-dump || command -v mysqldump'
```

If missing, the Alpine-based CrazyMax image can temporarily receive the client:

```bash
docker exec flarum2-flarum-1 apk add --no-cache mariadb-client
```

This extra package does not survive container recreation. A production deployment
should provide it through a derived image; do not unexpectedly recreate the
existing forum container to achieve that. Database credentials come from
Flarum's `config.php`. The user must be able to export tables, triggers, routines
and events. Export failure prevents data cleanup. Non-InnoDB base tables also
block this first version's cleanup path.

## B. Native Flarum installation

These examples deliberately target the existing Flarum 1.8 installation at
`/var/www/flarum`, with web user `www-data`. For a native Flarum 2 installation,
replace the path and user accordingly. Keep it separate from the Docker forum.

Upload the ZIP to `/tmp`, then extract outside the public web directory:

```bash
install -d -m 755 /opt/el-choco
unzip -o /tmp/extension-uninstaller-0.1.0-rc.1.zip -d /opt/el-choco
cd /var/www/flarum
runuser -u www-data -- composer config repositories.el-choco-uninstaller '{"type":"path","url":"/opt/el-choco/extension-uninstaller","options":{"symlink":false,"versions":{"el-choco/extension-uninstaller":"0.1.0-rc.1"}}}'
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Finish any active package jobs. If the native Admin Console service exists,
stop it during the actual installation:

```bash
systemctl stop el-choco-admin-console-flarum1.service
```

After the preview succeeds:

```bash
cd /var/www/flarum
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.1" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
runuser -u www-data -- php flarum extension:enable el-choco-extension-uninstaller && \
runuser -u www-data -- php flarum cache:clear
```

Reload the PHP-FPM service actually serving this forum, and restart the native
Admin Console service if it was stopped. Do not copy a service name for another
PHP version blindly.

Review `resources/el-choco-uninstaller-flarum1.service`: it uses `User=www-data`,
`Group=www-data`, `/var/www/flarum` and `/usr/bin/php`. Then install it:

```bash
cp /opt/el-choco/extension-uninstaller/resources/el-choco-uninstaller-flarum1.service /etc/systemd/system/el-choco-uninstaller-flarum1.service
systemctl daemon-reload
systemctl enable --now el-choco-uninstaller-flarum1.service
systemctl status el-choco-uninstaller-flarum1.service --no-pager -l
```

Custom `site.php` paths require matching `--storage=/absolute/path` and
`--public=/absolute/path` worker options. Purge also requires a dump utility on
the host. Do not run Composer, the worker or Flarum actions as root.

## C. Use the admin page

Hard-refresh, open Extension Uninstaller, and check that the background service
is connected. Select an expendable extension and start with Disable only.
Prepare a preview, inspect its impact, type `DISABLE` for disable-only or `DELETE` for uninstall,
and confirm. Review output/warnings and reload afterward.

Next test keep-data removal and reinstall it to verify data retention. Only
test Purge on a separate database copy, including a real manual restore test.
Purge requires an additional explicit confirmation. Its rollback migrations
are supplied by the removed extension; leftover settings, uploads or other data
may remain. No tables or settings are guessed by name and deleted.
Extension-specific uninstall hooks can also delete files. A SQL export does not
back up uploaded files; keep a full file backup before using cleanup.

Removal uses Composer with scripts and plugins disabled. Environments requiring
custom Composer hooks need a separate integration; CrazyMax extension sync is
implemented explicitly. Other package managers and manual Composer commands must
remain idle during operations. Our shared lock coordinates our two workers only.

## D. Backups and interrupted operations

Private state lives in `storage/extension-uninstaller`; snapshots are under
`backups/ID`. SQL exports contain personal information and are not encrypted.
**This storage directory must not be accessible over HTTP.** Prefer keeping it
outside the public web root. If the web root contains the whole forum directory,
configure the server to deny `/storage/`. For Nginx, an appropriate rule in the
existing server block is `location ^~ /storage/ { deny all; }`. Do not replace
an entire server configuration with a generic example.

Completed job logs expire after seven days or above 100 records on a later
worker pass. Plans and package/database backups are not automatically deleted
in this first version. Apply your own retention policy after verifying recovery.

A package snapshot contains Composer configuration/lock and, for CrazyMax, the
extension list. It does not contain installed package code or constitute a
database backup. `manifest.json` records whether a SQL export exists and its
SHA-256. Successful export is not a tested restore. There is no automatic rollback.

An interrupted job blocks further work. Inspect child processes, package state,
activation state, the snapshot, and database changes in the terminal. Do not
automatically retry or blindly kill package processes. Docker logs:

```bash
journalctl -u el-choco-uninstaller-flarum2.service -n 80 --no-pager
```

Only after manual recovery, and once no child remains active, stop the worker,
acknowledge the affected ID, and restart it:

```bash
systemctl stop el-choco-uninstaller-flarum2.service
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /opt/flarum/vendor/el-choco/extension-uninstaller/bin/worker.php --base=/opt/flarum --acknowledge=JOB_ID
systemctl start el-choco-uninstaller-flarum2.service
```

Replace `JOB_ID`. Acknowledging does not repair anything; it records that manual
inspection/recovery already happened. For native Flarum use its own service and
`runuser -u www-data -- php ... --base=/var/www/flarum` instead.

Before updating this extension, finish jobs and stop its worker. Start it again
after the update so it loads new classes; reload PHP-FPM if web OPcache is stale.
When migrating to a future Packagist release, separately remove the local path
repository so it does not override published releases.
