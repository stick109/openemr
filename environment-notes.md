# Environment Notes

## 2026-04-29: PHP CLI unavailable from PowerShell

- Symptom: Running `php -l` failed with `php : The term 'php' is not recognized as the name of a cmdlet, function, script file, or operable program.` `composer` was also not found on `PATH`.
- Likely cause: The Windows host environment does not have PHP CLI and Composer installed or added to `PATH`.
- Resolution: Installed PHP CLI 8.5.5 with `winget install --id PHP.PHP.8.5 --source winget --accept-package-agreements --accept-source-agreements`.
- Install path: `C:\Users\s-109\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`.
- Verification: `php -v` works after refreshing the current process PATH from the user and machine environment variables; direct invocation of the installed `php.exe` also works. `php -l .\src\Services\Agent\AgentIntentCatalog.php` passed.
- Current caveat: Existing PowerShell processes may not see the new `PATH` entry until restarted or until `$env:Path` is refreshed from `[Environment]::GetEnvironmentVariable('Path','Machine')` and `[Environment]::GetEnvironmentVariable('Path','User')`.
- Follow-up needed: Composer is still not installed on the Windows host. Use the OpenEMR Docker PHP container for Composer/PHPUnit until Composer is installed locally, or install Composer separately if host-side dependency commands are required.
