# Environment Notes

## 2026-04-29: PHP CLI unavailable from PowerShell

- Symptom: Running `php -l` failed with `php : The term 'php' is not recognized as the name of a cmdlet, function, script file, or operable program.` `composer` was also not found on `PATH`.
- Likely cause: The Windows host environment does not have PHP CLI and Composer installed or added to `PATH`.
- Workaround: Use an OpenEMR Docker PHP container for PHP syntax checks and PHPUnit, or install PHP CLI and Composer on the Windows host and add them to `PATH`.
- Follow-up needed: Confirm the preferred local PHP execution path for this repo so future agents can run `php -l`, `composer`, and PHPUnit without rediscovering the missing host tools.
