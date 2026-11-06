# Update from RC1 to RC2

These commands use `el-choco/extension-uninstaller:0.1.0-rc.2` from Packagist.
They work after the GitHub tag `v0.1.0-rc.2` has appeared on Packagist.

RC2 includes the confirmed local path-metadata fix. A previously applied RC1
hotfix is replaced by the normal package update; do not reapply it afterward.
Existing jobs and backups in `storage/extension-uninstaller` are retained.

Finish active jobs, keep a current forum backup and pause other package changes.
If Admin Console is installed, finish its jobs and pause its worker during the
update as well. No source ZIP, extra source directory or container recreation
is required.

## A. Native installation

Example: forum `/var/www/flarum`, PHP user `www-data`, existing service
`el-choco-uninstaller-native.service`. Use your existing service name if it
differs; do not create a second worker.

### 1. Stop the worker

```bash
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
```

If installed, also pause the native Admin Console service after its jobs finish.
In our previous native example it is named `el-choco-admin-console-flarum1.service`.
This optional step is unnecessary without Admin Console.

### 2. Remove a previous local repository, if present

Only for previous path-based test installations: the `el-choco-uninstaller`
repository may point to a local source directory or the installed `vendor`
package itself, blocking the Packagist release.

```bash
runuser -u www-data -- composer config --unset repositories.el-choco-uninstaller
```

This removes only that repository entry from the forum's `composer.json`, not
files or database data. Keep other repositories, including workbench/FA Picker
sources. If the same repository was separately configured globally for the forum
user, remove that specific global entry too. Do not clear all repositories.

### 3. Preview and apply the update

```bash
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

Stop if unexpected package changes appear. After a successful preview:

```bash
runuser -u www-data -- composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
runuser -u www-data -- php flarum cache:clear
```

### 4. Reload web PHP and restart the worker

Reload the PHP-FPM service actually serving the forum if OPcache retains old
code. List available services:

```bash
systemctl list-units --type=service 'php*-fpm.service'
```

Example only for a forum actually using PHP 8.4-FPM:

```bash
systemctl reload php8.4-fpm
```

Then start the existing uninstaller service:

```bash
systemctl start el-choco-uninstaller-native.service
systemctl status el-choco-uninstaller-native.service --no-pager -l
```

Restart Admin Console too if it was paused.

## B. CrazyMax Docker

Example: container `flarum2-flarum-1`, internal forum path `/opt/flarum`, container
user `flarum`, service `el-choco-uninstaller-flarum2.service`. Run commands on the
Docker host. `/opt/flarum` is an internal container path, not a native path requirement.

### 1. Stop the worker

```bash
systemctl stop el-choco-uninstaller-flarum2.service
```

If present, also pause `el-choco-admin-console.service` after its jobs finish.

### 2. Remove an old local source, if applicable

For previous local test installations only:

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer config --unset repositories.el-choco-uninstaller
```

This does not delete source files or other repositories. A separately configured
global entry under the same name must also be removed from that global configuration.

### 3. Preview the update

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --dry-run --update-no-dev --minimal-changes --no-scripts --no-interaction
```

### 4. Apply after a successful preview

```bash
docker exec --user flarum --env COMPOSER_HOME=/data/extensions/composer-home --workdir /opt/flarum flarum2-flarum-1 composer require el-choco/extension-uninstaller:"0.1.0-rc.2" --update-no-dev --minimal-changes --no-scripts --no-interaction && \
docker exec --user flarum flarum2-flarum-1 extension sync && \
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php flarum cache:clear
```

Stop on Composer failure. Synchronize the extension list only after success.

### 5. Reload web PHP and restart the worker

```bash
docker exec flarum2-flarum-1 s6-svc -r /run/service/php-fpm
systemctl start el-choco-uninstaller-flarum2.service
systemctl status el-choco-uninstaller-flarum2.service --no-pager -l
```

Restart a previously paused Admin Console service too. Do not recreate the
container or change its volumes for this update.

## C. Verify

Hard-refresh the browser, check version `0.1.0-rc.2` and the worker connection,
then request a fresh preview. Old plans no longer match the changed package
state. Review the proposed package list. `DELETE` / `DISABLE` confirmations remain.

If a relative repository configuration still blocks a preview, see
[INSTALL-EN.md](INSTALL-EN.md#e-local-composer-package-repositories). Repository
URLs must be absolute for the isolated preview; equivalent lock metadata may
remain relative when it resolves to the same existing directory.

On errors, inspect the original output and failed step before attempting any
additional repair or rollback commands.
