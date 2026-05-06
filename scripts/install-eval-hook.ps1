<#
.SYNOPSIS
    Install the agent-eval pre-push hook on Windows.

.DESCRIPTION
    Copies scripts/hooks/pre-push to .git/hooks/pre-push so that git push
    runs the agent eval before contacting the remote. Non-destructive: if
    a pre-push hook already exists at the target path, it is backed up to
    pre-push.bak.<unix-timestamp> before being overwritten.

    The hook itself is a bash script and runs under Git Bash on Windows
    (which is what `git push` uses to execute hooks). We do not need to
    set the executable bit on Windows -- Git Bash respects the shebang
    directly.

    To bypass the hook for a single push (emergencies only):
        $env:SKIP_EVAL_HOOK = "1"; git push; Remove-Item Env:SKIP_EVAL_HOOK

    See scripts/hooks/pre-push for full documentation.

.EXAMPLE
    PS> ./scripts/install-eval-hook.ps1
#>

[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"

$repoRoot = (& git rev-parse --show-toplevel 2>$null)
if (-not $repoRoot) {
    Write-Error "must be run from inside the openemr git working tree."
    exit 1
}

# git rev-parse returns forward slashes on Windows; normalise to platform paths.
$repoRoot = $repoRoot.Trim()

$sourceHook = Join-Path -Path $repoRoot -ChildPath "scripts/hooks/pre-push"
$hooksDir = Join-Path -Path $repoRoot -ChildPath ".git/hooks"
$targetHook = Join-Path -Path $hooksDir -ChildPath "pre-push"

if (-not (Test-Path -LiteralPath $sourceHook -PathType Leaf)) {
    Write-Error "source hook not found at $sourceHook."
    exit 1
}

if (-not (Test-Path -LiteralPath $hooksDir)) {
    New-Item -ItemType Directory -Path $hooksDir -Force | Out-Null
}

if (Test-Path -LiteralPath $targetHook) {
    $timestamp = [int][double]::Parse((Get-Date -UFormat %s))
    $backupPath = "$targetHook.bak.$timestamp"
    Write-Warning "an existing pre-push hook was found at $targetHook."
    Write-Warning "         backing it up to $backupPath and overwriting."
    Move-Item -LiteralPath $targetHook -Destination $backupPath -Force
}

Copy-Item -LiteralPath $sourceHook -Destination $targetHook -Force

Write-Host "Installed agent-eval pre-push hook at $targetHook."
Write-Host 'Bypass once with: $env:SKIP_EVAL_HOOK = "1"; git push; Remove-Item Env:SKIP_EVAL_HOOK'
