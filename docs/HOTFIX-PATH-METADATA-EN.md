# Hotfix: local path-package metadata

Historical instructions for the standalone RC1 hotfix. **RC2 includes the fix; do not reapply this hotfix after updating.**

This hotfix targets the known RC.1 `PlanService.php` file. It does not run
Composer, migrations or database changes. The original file is backed up privately.

Composer may rewrite an unchanged path package's `dist.url` and
`transport-options.relative` during a preview. The fix accepts this only when
both paths resolve to the same existing directory, anchored to the forum root
and preview directory respectively. All remaining package metadata must match.
The original lock entry is retained for such packages. Versions, references,
dependencies, autoload rules, different sources and other options remain protected.

## Native Flarum

Finish active jobs. Upload the ZIP as `/tmp/uninstaller-path-metadata-fix.zip`.
Adjust the forum directory, PHP user and existing service name if necessary.
Do not create a second service.

```bash
unzip -o /tmp/uninstaller-path-metadata-fix.zip -d /tmp
systemctl stop el-choco-uninstaller-native.service
cd /var/www/flarum
runuser -u www-data -- php /tmp/uninstaller-path-metadata-fix/apply.php --check
```

After a successful check:

```bash
runuser -u www-data -- php /tmp/uninstaller-path-metadata-fix/apply.php --apply && \
systemctl start el-choco-uninstaller-native.service
```

Keep the printed backup ID. Request a fresh preview in the admin area. No
Composer operation or asset/cache rebuild is needed for this worker-only fix.

## CrazyMax Docker

Upload the ZIP to `/mnt/wdblue/flarum2`. Finish active jobs first:

```bash
unzip -o /mnt/wdblue/flarum2/uninstaller-path-metadata-fix.zip -d /mnt/wdblue/flarum2/data
systemctl stop el-choco-uninstaller-flarum2.service
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /data/uninstaller-path-metadata-fix/apply.php --check
```

After a successful check:

```bash
docker exec --user flarum --workdir /opt/flarum flarum2-flarum-1 php /data/uninstaller-path-metadata-fix/apply.php --apply && \
systemctl start el-choco-uninstaller-flarum2.service
```

## Restore

Stop the worker and run the printed `--restore=BACKUP_ID` command as the same
forum user, from the forum directory. Restart the same service afterward.
Backups are stored under `storage/extension-uninstaller/backups/path-metadata-...`.
Unknown file contents are rejected; do not force old backups over later releases.

## Validation

21 focused path-metadata cases and a real offline Composer scenario with a local
Git path package verify the behavior, alongside the existing safety checks.
The hotfix installer is checked read-only and for PHP syntax locally. Applying
and restoring it on the target Linux forum still need practical confirmation.
