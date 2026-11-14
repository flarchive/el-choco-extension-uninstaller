# Extension Uninstaller 0.1.0-rc.2

RC2 fixes rejected removal previews for unchanged local path packages and
provides corrected Packagist installation and update instructions.

## Changes

- Accept changes to a path package's URL/relative flag only when both URLs resolve
  to the same existing directory and all other package metadata remains identical.
- Retain the original lock entry for unchanged local packages.
- Recognize equivalent path URLs when checking installed metadata against the lock
  file, including after a Composer metadata refresh.
- Keep blocking changed versions, references, sources, dependencies and autoload rules.
- Include affected package names and changed field names in rejected-preview errors.
- Replace old ZIP/path-test installation steps with Packagist instructions.
- Include complete native and CrazyMax systemd service examples.
- Provide RC1-to-RC2 update guides in English and German, including removal of the
  specific obsolete uninstaller path repository when migrating to Packagist.

The user confirmed that the RC1 path hotfix resolved the native Flarum 1 workflow.
RC2 incorporates that fix and adds coverage for installed/lock metadata checks.
The standalone RC1 hotfix is no longer needed after updating.

## Update

Publish tag `v0.1.0-rc.2` and wait for Packagist to list it before using the commands
in [UPDATE-EN.md](../UPDATE-EN.md). Finish active jobs and stop the worker before
updating. Restart it afterward; no service recreation or state-directory deletion
is required. The package remains a release candidate. Full SQL export/restore
and failure-recovery validation is not claimed by this release.
