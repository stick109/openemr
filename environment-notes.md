# Environment Notes

Last verified: 2026-04-29

## Current State

- PHP CLI 8.5.5 is installed on the Windows host and available as `php`.
- Composer 2.9.7 is available on PATH as `composer`.
- Composer resolves to `C:\Users\s-109\AppData\Local\Composer\composer.bat`.
- The active PHP CLI configuration file is `C:\Users\s-109\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.ini`.
- Composer diagnostics pass in this checkout.
- PHP dependencies are installed; `vendor\autoload.php` and `vendor\bin\phpunit.bat` are present.
- Node dependencies are installed; `node_modules\.bin\gulp.cmd` is present.

## PHP Extensions

The Windows PHP CLI currently loads the extensions needed for Composer and the known PHPUnit blockers:

- `curl`
- `fileinfo`
- `intl`
- `mbstring`
- `mysqli`
- `openssl`
- `pdo_mysql`
- `redis`
- `zip`

The Redis extension is a manually installed PECL DLL. If PHP is upgraded, replace `php_redis.dll` with a build matching the new PHP version, thread-safety mode, compiler, and architecture.

## Common Commands

- Restore PHP dependencies: `composer install`
- Regenerate Composer autoload files: `composer dump-autoload`
- Restore Node dependencies: `npm ci`
- Run targeted agent PHPUnit tests: `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml --group agent`

## Docker Notes

- For forced recreates of the OpenEMR easy-dev containers, allow a 5-6 minute health wait before treating startup as failed.
- Prefer `http://localhost:8300/...` for local OpenEMR health checks unless the HTTPS path itself is under test.
- 2026-04-29: Symptom: running `docker compose up --detach --wait` from `docker\development-easy` can fail while starting Selenium with `Bind for 0.0.0.0:4444 failed: port is already allocated`. Likely cause: an active easy-dev stack was already running with Compose project name `openemr`, while the default project name from the directory is `development-easy`, so Compose tried to start a duplicate stack on the same host ports. Workaround: target the active stack explicitly with `docker compose -p openemr up --detach --wait` and `docker compose -p openemr exec openemr ...`, or stop the `openemr` project first if a fresh project is intended. Follow-up: after any future failed duplicate start, remove partial `development-easy-*` containers and unused `development-easy_*` volumes when they are no longer needed.

## Windows PowerShell Notes

- This host's Windows PowerShell does not support `Invoke-WebRequest -SkipCertificateCheck`.
- For local OpenEMR readiness checks, use HTTP endpoints, Docker health status, or a PowerShell/.NET certificate callback only when HTTPS response content must be inspected.

## Remaining Environment Work

- No Composer/PHP dependency setup is currently pending.
- The full isolated PHPUnit suite is not a clean Windows-host baseline. Latest run of `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml` on 2026-04-29 completed with `Tests: 2766, Assertions: 7086, Errors: 4, Failures: 340, Warnings: 4, Notices: 1, Skipped: 14, Incomplete: 14`.
- Remaining Windows-host failure categories include path/routing expectations, Twig template path handling, CRLF fixture output differences, subprocess spawning behavior, POSIX-style permission checks, and symlink creation permissions.
- Use targeted Windows suites for local iteration, and use the Linux Docker environment for broad validation.
