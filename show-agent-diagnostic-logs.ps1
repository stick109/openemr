param(
    [int]$Hours = 24,
    [switch]$Pretty
)

$ErrorActionPreference = "Stop"

if ($Hours -le 0) {
    throw "-Hours must be a positive integer."
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw "Docker CLI was not found. Install Docker Desktop, then run this script again."
}

$ProjectName = "openemr"
$culture = [System.Globalization.CultureInfo]::InvariantCulture

function Find-JsonSegmentEnd {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Text,

        [Parameter(Mandatory = $true)]
        [int]$Start
    )

    $open = $Text[$Start]
    if ($open -ne "{" -and $open -ne "[") {
        return -1
    }

    $stack = New-Object System.Collections.Generic.List[char]
    $stack.Add([char]$open)
    $inString = $false
    $escaped = $false

    for ($i = $Start + 1; $i -lt $Text.Length; $i++) {
        $char = $Text[$i]

        if ($inString) {
            if ($escaped) {
                $escaped = $false
            } elseif ($char -eq "\") {
                $escaped = $true
            } elseif ($char -eq '"') {
                $inString = $false
            }
            continue
        }

        if ($char -eq '"') {
            $inString = $true
            continue
        }

        if ($char -eq "{" -or $char -eq "[") {
            $stack.Add([char]$char)
            continue
        }

        if ($char -ne "}" -and $char -ne "]") {
            continue
        }

        $lastIndex = $stack.Count - 1
        $lastOpen = $stack[$lastIndex]
        $expectedClose = if ($lastOpen -eq "{") { "}" } else { "]" }
        if ($char -ne $expectedClose) {
            return -1
        }

        $stack.RemoveAt($lastIndex)
        if ($stack.Count -eq 0) {
            return $i
        }
    }

    return -1
}

function Format-EmbeddedJson {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Line
    )

    $output = New-Object System.Collections.Generic.List[string]
    $emitCursor = 0
    $searchCursor = 0
    $foundJson = $false

    while ($searchCursor -lt $Line.Length) {
        $start = $Line.IndexOfAny([char[]]@("{", "["), $searchCursor)
        if ($start -lt 0) {
            break
        }

        $end = Find-JsonSegmentEnd -Text $Line -Start $start
        if ($end -lt 0) {
            $searchCursor = $start + 1
            continue
        }

        $candidate = $Line.Substring($start, $end - $start + 1)
        try {
            $parsed = $candidate | ConvertFrom-Json -ErrorAction Stop
            $prefix = $Line.Substring($emitCursor, $start - $emitCursor).Trim()
            if ($prefix -ne "") {
                $output.Add($prefix)
            }

            $trimmedCandidate = $candidate.Trim()
            if ($trimmedCandidate -eq "[]") {
                $prettyJson = "[]"
            } elseif ($trimmedCandidate.StartsWith("[")) {
                $prettyJson = ConvertTo-Json -InputObject @($parsed) -Depth 100
            } else {
                $prettyJson = ConvertTo-Json -InputObject $parsed -Depth 100
            }

            foreach ($jsonLine in $prettyJson) {
                $output.Add($jsonLine)
            }

            $foundJson = $true
            $emitCursor = $end + 1
            $searchCursor = $emitCursor
        } catch {
            $searchCursor = $start + 1
        }
    }

    if (-not $foundJson) {
        return $Line
    }

    $suffix = $Line.Substring($emitCursor).Trim()
    if ($suffix -ne "") {
        $output.Add($suffix)
    }

    return $output
}

# The Apache error log timestamps are in container-local time, so anchor the
# cutoff to the container's clock instead of the Windows host clock.
$nowRaw = & docker compose --project-name $ProjectName exec -T openemr date "+%Y-%m-%d %H:%M:%S"
if ($LASTEXITCODE -ne 0) {
    throw "Failed to read container time (exit $LASTEXITCODE)."
}

$nowLine = $nowRaw | Where-Object { $_ -match '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$' } | Select-Object -Last 1
if (-not $nowLine) {
    throw "Could not parse container time from output: $($nowRaw -join '|')"
}

$containerNow = [DateTime]::ParseExact($nowLine, "yyyy-MM-dd HH:mm:ss", $culture)
$cutoff = $containerNow.AddHours(-$Hours)

$rawLines = & docker compose --project-name $ProjectName exec -T openemr cat /var/log/apache2/error.log
if ($LASTEXITCODE -ne 0) {
    throw "Failed to read PHP error log (exit $LASTEXITCODE)."
}

# Apache error log line prefix: [Thu Apr 30 00:19:54.433214 2026]
$timestampPattern = '^\[(?<dow>[A-Z][a-z]{2}) (?<mon>[A-Z][a-z]{2})\s+(?<day>\d{1,2}) (?<time>\d{2}:\d{2}:\d{2})(?:\.\d+)? (?<year>\d{4})\]'

# Agent code emits PSR-3 messages through OpenEMR\Common\Logging\SystemLogger,
# which formats them as "OpenEMR.<LEVEL>: <message>". Agent emissions use a
# dotted "agent." message prefix (see AgentEvidenceToolset::timedRead).
$agentPattern = 'OpenEMR\.[A-Z]+: agent\.'

$emitted = 0
foreach ($line in $rawLines) {
    if ($line -notmatch $agentPattern) { continue }

    if ($line -match $timestampPattern) {
        $tsString = "$($Matches.mon) $($Matches.day) $($Matches.year) $($Matches.time)"
        try {
            $entryTime = [DateTime]::ParseExact($tsString, "MMM d yyyy HH:mm:ss", $culture)
            if ($entryTime -lt $cutoff) { continue }
        } catch {
            # Could not parse a timestamp we matched; emit anyway so nothing is lost silently.
        }
    }

    if ($Pretty) {
        Write-Output (Format-EmbeddedJson -Line $line)
    } else {
        Write-Output $line
    }
    $emitted++
}

if ($emitted -eq 0) {
    Write-Host "No agent diagnostic log records in the last $Hours hour(s)." -ForegroundColor Yellow
}
