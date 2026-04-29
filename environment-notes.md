# Environment Notes

## 2026-04-29: PHP CLI installed

- PHP CLI 8.5.5 is installed on the Windows host and available as `php`.
- If Composer becomes the best tool for a task and is not installed, ask the user to install Composer before switching to any workaround.

## 2026-04-29: Composer and vendor bin unavailable

- Symptom: `php vendor\bin\phpunit -c phpunit-isolated.xml --group agent` failed because `vendor\bin\phpunit` could not be opened, and `vendor\bin` is absent.
- Likely cause: Composer is not installed on the Windows host, and the `vendor` directory is an empty OneDrive reparse-point directory in this checkout.
- Workaround: Use available PHP syntax checks until Composer is installed and `composer install` restores dev dependencies.
- Follow-up: Install Composer, run `composer install`, then use `.\vendor\bin\phpunit.bat -c phpunit-isolated.xml --group agent` from PowerShell.
