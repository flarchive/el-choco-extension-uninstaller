# Changelog

## 0.1.0-rc.2

- Accept local path URL/relative-flag rewrites only when both paths resolve to the same existing directory and all other package metadata matches.
- Preserve original metadata for unchanged local packages in the approved lock file.
- Identify the affected package and changed field names when a preview is rejected.
- Accept equivalent local path URL representations when comparing installed packages with the lock file, while retaining reference and source checks.
- Correct Packagist installation guides and native worker paths.
- Add RC1-to-RC2 update guides in German and English.
- Add focused path-metadata tests and a real Composer scenario with a local Git extension.

## 0.1.0-rc.1

- Change the banner background to #4d698e.
- Use normal casing for the el-choco banner label.
- Remove the large promotional heading and use a professional description.
- Retain DELETE/DISABLE confirmation and existing removal behavior.
- Release candidate; pending live database-export/restore validation remains documented.

## 0.1.0-alpha.2

- Use `DELETE` to confirm uninstall and `DISABLE` to confirm disable-only.
- Display package names and versions separately; no package-name copying needed.
- Preserve the additional database-cleanup confirmation.
- Add frontend and real-Flarum API checks for confirmation words.

## 0.1.0-alpha.1

- Standalone admin page for Flarum 1.8 and 2.x.
- Disable, remove while retaining data, or back up and invoke Flarum purge.
- Isolated Composer plan with exact package-removal validation and ten-minute expiry.
- Durable jobs, private snapshots and independent non-root background worker.
- Optional coordination with Admin Console and explicit CrazyMax extension sync.
- German and English interface and installation documentation.
- Initial local safety, authorization, frontend and offline Composer tests.

Initial test release. Live Linux/database/export/restore validation remains pending.
