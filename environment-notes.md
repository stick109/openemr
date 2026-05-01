# Environment Notes

Last verified: 2026-05-01

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
- Run pipe/regex-filtered PHPUnit tests from PowerShell: `punit -c phpunit-isolated.xml --filter "AnonymizerTest|AgentIntentRestControllerTest" tests\Tests\Isolated\Services\Agent\AnonymizerTest.php tests\Tests\Isolated\RestControllers\Agent\AgentIntentRestControllerTest.php`
- Run shell scripts inside the default OpenEMR Compose service without nested quoting: pass a here-string to `dcsh`.

## Docker Notes

- For forced recreates of the OpenEMR easy-dev containers, allow a 5-6 minute health wait before treating startup as failed.
- Prefer `http://localhost:8300/...` for local OpenEMR health checks unless the HTTPS path itself is under test.
- 2026-04-30: Symptom: after `docker compose -p openemr restart openemr`, Docker health can remain `starting` or briefly become `unhealthy`, and HTTPS health checks can fail with `curl: (7) Failed to connect to localhost port 443`, while the container is still running `rsync` from `/openemr` before Apache starts. Likely cause: the easy-dev entrypoint copies the bind-mounted checkout into the served tree on restart, and Apache/443 is not listening until that startup work finishes. Workaround: inspect `docker compose -p openemr exec -T openemr ps aux` and wait for `/usr/sbin/httpd -D FOREGROUND`; then wait for one more Docker health-check cycle. Follow-up: only investigate as a startup failure if Apache never starts or health does not recover after the copy completes.
- 2026-04-29: Symptom: running `docker compose up --detach --wait` from `docker\development-easy` can fail while starting Selenium with `Bind for 0.0.0.0:4444 failed: port is already allocated`. Likely cause: an active easy-dev stack was already running with Compose project name `openemr`, while the default project name from the directory is `development-easy`, so Compose tried to start a duplicate stack on the same host ports. Workaround: target the active stack explicitly with `docker compose -p openemr up --detach --wait` and `docker compose -p openemr exec openemr ...`, or stop the `openemr` project first if a fresh project is intended. Follow-up: after any future failed duplicate start, remove partial `development-easy-*` containers and unused `development-easy_*` volumes when they are no longer needed.

## Windows PowerShell Notes

- For local OpenEMR readiness checks, use HTTP endpoints, Docker health status, or a PowerShell/.NET certificate callback only when HTTPS response content must be inspected.
- 2026-05-01: Symptom: using `[System.Net.Http.HttpClient]` in Windows PowerShell without first loading the assembly can fail with `Unable to find type [System.Net.Http.HttpClient]`. Likely cause: Windows PowerShell does not always auto-load `System.Net.Http` for inline probes. Workaround: run `Add-Type -AssemblyName System.Net.Http` before constructing `HttpClient`, or use `Invoke-WebRequest` for simple HTTP checks. Follow-up: prefer `Invoke-WebRequest` unless raw byte access is needed.
- 2026-04-30: Symptom: piping inline PHP from a PowerShell here-string into `php` can fail in session-using code with `Failed to start the session because headers have already been sent by "Standard input code" at line 1`. Likely cause: the pipeline can prepend or emit a UTF-8 BOM before PHP starts, so PHP sees output before session headers. Workaround: avoid PowerShell here-string stdin for PHP probes that bootstrap OpenEMR sessions; use a temporary checked script, `php -r` only for quote-light code that does not need sessions, or an existing PHPUnit/render test. Follow-up: keep session-sensitive render probes in tests rather than ad hoc stdin scripts.
- 2026-04-30: Symptom: running an isolated test with the default PHPUnit config, such as `vendor\bin\phpunit.bat --filter PatientAgentTabTest tests\Tests\Isolated\Interface\PatientAgentTabTest.php`, can fail during `tests\bootstrap.php` with `mysqli_query(): Argument #1 ($mysql) must be of type mysqli, false given` and `CRITICAL ERROR: Safety-net bootstrap did not load`. Likely cause: `phpunit.xml` loads the full OpenEMR bootstrap and expects a working database connection, while isolated tests are meant to run with `phpunit-isolated.xml` and `DISABLE_DATABASE=1`. Workaround: run isolated tests with `vendor\bin\phpunit.bat -c phpunit-isolated.xml --filter PatientAgentTabTest tests\Tests\Isolated\Interface\PatientAgentTabTest.php`. Follow-up: keep targeted isolated-test commands pinned to `phpunit-isolated.xml`.
- 2026-04-29: Symptom: invoking `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml --filter "A|B"` can fail with `'B' is not recognized as an internal or external command`. Likely cause: the Windows batch wrapper passes the filter through `cmd.exe`, where `|` is treated as a command pipe despite the PowerShell quoting. Workaround: the global PowerShell profile defines `punit` / `phpunit-safe`, which call the repo-local PHPUnit PHP entrypoint with `php` and bypass `cmd.exe`; use that for any `--filter` with regex alternation. Follow-up: do not use the `.bat` wrapper for pipe-containing PHPUnit filters.
- 2026-04-29: Symptom: host-side `php bin\console ...` can fail after a Docker easy-dev install with `mysqli_query(): Argument #1 ($mysql) must be of type mysqli, false given`. Likely cause: `sites\default\sqlconf.php` points at the Compose service hostname `mysql`, which resolves inside Docker but not from the Windows host. Workaround: run OpenEMR console/app checks inside the container with `docker compose -p openemr exec openemr ...`, or use host-accessible database settings only for a host-native install. Follow-up: keep Docker and host-native runtime assumptions separate.
- 2026-04-29: Symptom: after `docker compose -p openemr exec openemr /root/devtools dev-reset-install`, `http://localhost:8300/meta/health/readyz` can still return `{"status":"setup_required","checks":{"installed":false,...}}` while the login page works and the OpenEMR container is Docker-healthy. Likely cause: the health endpoint's readiness checks are stricter/different from the development login path, including OAuth key checks. Workaround: for local development, verify `http://localhost:8300/interface/login/login.php?site=default` and `docker compose -p openemr ps`; do not treat the readyz JSON alone as proof the dev UI is unavailable. Follow-up: revisit if deployment health probes depend on this endpoint.
## Remaining Environment Work

- No Composer/PHP dependency setup is currently pending.
- The full isolated PHPUnit suite is not a clean Windows-host baseline. Latest run of `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml` on 2026-04-29 completed with `Tests: 2766, Assertions: 7086, Errors: 4, Failures: 340, Warnings: 4, Notices: 1, Skipped: 14, Incomplete: 14`.
- Remaining Windows-host failure categories include path/routing expectations, Twig template path handling, CRLF fixture output differences, subprocess spawning behavior, POSIX-style permission checks, and symlink creation permissions.
- Use targeted Windows suites for local iteration, and use the Linux Docker environment for broad validation.
