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
