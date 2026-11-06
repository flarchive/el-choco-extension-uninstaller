# Extension Uninstaller 0.1.0-rc.1

This release candidate refines the admin interface while retaining the existing
preview, confirmation and removal workflow.

- Solid banner background: `#4d698e`.
- Normal-case banner label: `el-choco · Erweiterungen Verwalten` in German.
- Removed the large promotional heading.
- Professional German and English descriptions.
- Retained `DELETE` / `DISABLE` confirmation and separate database-cleanup consent.
- Updated local-install and update guides for `0.1.0-rc.1`.

Both frontend production builds and their 12 tests pass. Real Flarum 1.8.21 and
2.0.2 dependency checks for authorization, CSRF, API confirmation and Less styles
pass with 26 checks per version. The static design preview was visually checked.

This is a release candidate, not a stable production release. Full live Linux
and database-export/restore validation remains listed in `TEST-REPORT.md`.
The package has not been published to Packagist by this local build.
