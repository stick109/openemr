# Environment Notes

## 2026-04-29: PHP CLI and Composer installed

- PHP CLI 8.5.5 is installed on the Windows host and available as `php`.
- Composer 2.9.7 is installed at `C:\Users\s-109\AppData\Local\Composer\composer.phar`.
- `composer.bat` and `composer.cmd` wrappers were added in `C:\Users\s-109\AppData\Local\Composer`, and that directory is in the user PATH. Already-running shells may need their PATH refreshed or a restart before plain `composer` resolves.

## 2026-04-29: Composer install required PHP OpenSSL

- Symptom: The Composer installer initially reported that PHP was missing the `openssl` extension and could not safely perform HTTPS transfers.
- Likely cause: The WinGet PHP package had no loaded `php.ini`, so bundled extensions such as `php_openssl.dll` were present but disabled.
- Workaround: Created `php.ini` from the bundled production template and appended `extension_dir="<PHP install>\ext"` plus `extension=openssl`.
- Follow-up: `composer diagnose` passes. It still reports PHP `curl` and `zip` are not loaded, so Composer uses PHP streams and 7-Zip fallback. Install/enable those extensions later if Composer performance or archive handling becomes a problem.

## 2026-04-29: Vendor dependencies not restored yet

- Symptom: `vendor\bin` was absent when trying to run PHPUnit, and the repo's `vendor` directory was empty.
- Likely cause: Dependencies have not been installed in this checkout.
- Workaround: Composer is now installed; run `composer install` when the project dependencies are needed.
- Follow-up: After `composer install`, run `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml --group agent` from PowerShell.

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
