# Environment Notes

## 2026-04-29: PHP CLI and Composer installed

- PHP CLI 8.5.5 is installed on the Windows host and available as `php`.
- Composer 2.9.7 is installed at `C:\Users\s-109\AppData\Local\Composer\composer.phar`.
- `composer.bat` and `composer.cmd` wrappers were added in `C:\Users\s-109\AppData\Local\Composer`, and that directory is in the user PATH. Already-running shells may need their PATH refreshed or a restart before plain `composer` resolves.

## 2026-04-29: Active shell did not resolve Composer from PATH

- Symptom: Running Composer from the repo root failed with `composer : The term 'composer' is not recognized as the name of a cmdlet, function, script file, or operable program`.
- Likely cause: The current PowerShell process was started before `C:\Users\s-109\AppData\Local\Composer` was added to the user PATH, so it has a stale PATH environment.
- Workaround: Use repo-local `php vendor\bin\...` commands when `vendor` is already restored, use `C:\Users\s-109\AppData\Local\Composer\composer.bat` directly from this shell, or refresh/restart the shell before using plain `composer`.
- Follow-up: After opening a fresh shell, confirm `Get-Command composer` resolves to the Composer wrapper.

## 2026-04-29: Composer install required PHP OpenSSL

- Symptom: The Composer installer initially reported that PHP was missing the `openssl` extension and could not safely perform HTTPS transfers.
- Likely cause: The WinGet PHP package had no loaded `php.ini`, so bundled extensions such as `php_openssl.dll` were present but disabled.
- Workaround: Created `php.ini` from the bundled production template and appended `extension_dir="<PHP install>\ext"` plus `extension=openssl`.
- Follow-up: `composer diagnose` passes. PHP `zip` was later enabled after archive handling became a problem. PHP `curl` is still not loaded, so enable it later if Composer HTTPS performance becomes a problem.

## 2026-04-29: Vendor dependencies restored

- Symptom: `vendor\bin` was absent when trying to run PHPUnit, and the repo's `vendor` directory was empty.
- Likely cause: Dependencies have not been installed in this checkout.
- Workaround: Ran `C:\Users\s-109\AppData\Local\Composer\composer.bat install` from the repo root after enabling required PHP extensions.
- Follow-up: Run `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml --group agent` from PowerShell when PHPUnit verification is needed.

## 2026-04-29: PHPUnit wrapper existed without Composer autoload

- Symptom: `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml --filter PatientAgentTabTest` failed with `Failed opening required ...\vendor\autoload.php`.
- Likely cause: The host `vendor` tree had package directories and wrappers, but Composer autoload files had not been generated.
- Workaround: Ran `C:\Users\s-109\AppData\Local\Composer\composer.bat dump-autoload` from the repo root to regenerate `vendor\autoload.php`.
- Follow-up: If the error returns after Docker volume resets or dependency changes, rerun `composer dump-autoload`; use `composer install` only when package directories are missing or stale.

## 2026-04-29: PHPUnit required PHP mbstring

- Symptom: After regenerating Composer autoloads, PHPUnit stopped with `the "mbstring" extension is not available`.
- Likely cause: `php_mbstring.dll` was bundled with the WinGet PHP install, but `php.ini` only had OpenSSL enabled.
- Workaround: Appended `extension=mbstring` to `C:\Users\s-109\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.ini`; `php -m` now lists `mbstring`.
- Follow-up: If PHPUnit or Composer reports other missing extensions, check the same `ext` directory first and enable bundled DLLs before installing another PHP distribution.

## 2026-04-29: Composer install required PHP Redis

- Symptom: `composer install` failed platform checks with `Root composer.json requires PHP extension ext-redis * but it is missing from your system`.
- Likely cause: The WinGet PHP package did not include or enable `php_redis.dll`.
- Workaround: Downloaded the matching PECL build `php_redis-6.3.0-8.5-ts-vs17-x64.zip` with `Invoke-WebRequest`, copied `php_redis.dll` into the active PHP `ext` directory, and appended `extension=redis` to `php.ini`. `php --ri redis` now reports Redis Support enabled and Redis Version 6.3.0.
- Follow-up: If PHP is upgraded, replace `php_redis.dll` with a build matching the new PHP version, thread-safety mode, compiler, and architecture.

## 2026-04-29: Composer install needed PHP zip fallback for symlinked archive

- Symptom: During `composer install`, 7-Zip failed to extract `yubico/u2flib-server` because it could not create a symbolic link: `A required privilege is not held by the client`.
- Likely cause: PHP `zip` was not loaded, so Composer used the 7-Zip fallback first; the Windows shell did not have symlink creation privileges.
- Workaround: Enabled the bundled `php_zip.dll` by appending `extension=zip` to `php.ini`. Composer retried extraction, fell back to ZipArchive after 7-Zip failed, and completed successfully.
- Follow-up: Keep PHP `zip` enabled for Composer installs on this checkout. Developer Mode or elevated symlink privilege would avoid this specific 7-Zip failure but is not required while ZipArchive fallback works.

## 2026-04-29: OpenEMR container health can exceed a 4-minute wait

- Symptom: After `docker compose --project-name openemr --file docker\development-easy\docker-compose.yml up -d --build --force-recreate`, a 4-minute polling loop timed out while `openemr-openemr-1` still reported `starting`.
- Likely cause: The easy-dev container performs startup work such as self-signed certificate setup and Xdebug installation before the health check settles.
- Workaround: Rechecked with `docker compose ... ps`; the container became `healthy` shortly after the timeout, and `http://localhost:8300/meta/health/readyz` returned 200.
- Follow-up: Use a 5-6 minute health wait for forced recreates before treating startup as failed.

## 2026-04-29: Windows PowerShell lacks Invoke-WebRequest SkipCertificateCheck

- Symptom: `Invoke-WebRequest -SkipCertificateCheck` failed with `A parameter cannot be found that matches parameter name 'SkipCertificateCheck'`.
- Likely cause: The active Windows PowerShell version does not support that parameter.
- Workaround: Use `http://localhost:8300/...` for local OpenEMR health checks, rely on Docker health status for HTTPS readiness, or use a PowerShell/.NET certificate callback only when HTTPS response content must be inspected.
- Follow-up: Prefer HTTP local health checks in this repo unless the HTTPS path itself is under test.

## 2026-04-29: Node dependencies may be present but incomplete

- Symptom: `npm run build` failed with `'gulp' is not recognized as an internal or external command` while `node_modules` existed.
- Likely cause: The checked-out `node_modules` tree was incomplete and did not contain the local `gulp` package or `node_modules\.bin\gulp.cmd`.
- Workaround: Ran `npm ci` from the repo root to restore dependencies from `package-lock.json`; `npm run build` then completed successfully.
- Follow-up: If build tools disappear again after Docker volume or dependency changes, rerun `npm ci` before using npm scripts.

## 2026-04-29: Full isolated PHPUnit suite is not clean on this Windows PHP CLI

- Symptom: `php vendor\bin\phpunit -c phpunit-isolated.xml` completed but failed with many unrelated errors and failures, including missing `finfo`, `locale_get_default`, and `MYSQLI_BOTH`, Windows permission-mode checks, CRLF fixture mismatches, and background subprocess path failures.
- Likely cause: The host PHP CLI is missing several extensions expected by the full suite (`fileinfo`, `intl`, `mysqli`), and some isolated tests assume POSIX permissions, Unix-style line endings, or Unix subprocess behavior.
- Workaround: Use targeted suites that do not depend on those host features; `php vendor\bin\phpunit -c phpunit-isolated.xml --group agent` passed.
- Follow-up: Enable the missing PHP extensions and run the full suite in the Linux Docker environment when broad validation is required.
