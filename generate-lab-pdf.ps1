# generate-lab-pdf.ps1 — synthetic lab PDF generator
#
# Generates a realistic-looking lab report PDF for the given patient by calling
# OpenAI for the report content (Structured Outputs) and rendering via OpenEMR's
# bundled mPDF inside the running 'openemr' container.
#
# PII contract: only patient INITIALS, AGE, and SEX are sent to OpenAI.
# Full names, DOB-as-date, address, phone, and SSN never leave the host.
#
# The PDF is saved to disk only — it is NOT inserted into the OpenEMR documents
# or procedure_* tables.

param(
    [Parameter(Mandatory)][int]$PatientId,
    [string]$ProjectName = "openemr",
    [string]$Panel,
    [string]$Model = "gpt-4o-mini",
    [string]$OutFile,
    [int]$Seed
)

$ErrorActionPreference = "Stop"

function Confirm-DockerCompose {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "Docker CLI was not found. Install Docker Desktop, then run this script again."
    }

    & docker compose version | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose v2 was not found. Install or enable the Docker Compose plugin, then run this script again."
    }
}

function Confirm-MysqlContainerRunning {
    $running = & docker compose --project-name $ProjectName ps --services --filter "status=running"
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose ps failed with exit code $LASTEXITCODE. Is the '$ProjectName' stack started?"
    }

    if (-not ($running -contains "mysql")) {
        throw "The 'mysql' service is not running for project '$ProjectName'. Start it with: docker compose --project-name $ProjectName up --detach --wait"
    }
}

function Confirm-OpenemrContainerRunning {
    $running = & docker compose --project-name $ProjectName ps --services --filter "status=running"
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose ps failed with exit code $LASTEXITCODE. Is the '$ProjectName' stack started?"
    }

    if (-not ($running -contains "openemr")) {
        throw "The 'openemr' service is not running for project '$ProjectName'. Start it with: docker compose --project-name $ProjectName up --detach --wait"
    }
}

function Get-OpenAIKeyFromEnvFile {
    $envPath = Join-Path $PSScriptRoot ".env"
    if (-not (Test-Path -LiteralPath $envPath)) { return $null }

    foreach ($line in (Get-Content -LiteralPath $envPath)) {
        $trimmed = $line.Trim()
        if ($trimmed -eq "" -or $trimmed.StartsWith("#")) { continue }
        if ($trimmed -notmatch '^\s*OPENAI_API_KEY\s*=\s*(.*)$') { continue }

        $value = $matches[1].Trim()
        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        if (-not [string]::IsNullOrWhiteSpace($value)) {
            return $value
        }
    }
    return $null
}

function Confirm-OpenAIKey {
    if (-not [string]::IsNullOrWhiteSpace($env:OPENAI_API_KEY)) { return }

    $fromEnvFile = Get-OpenAIKeyFromEnvFile
    if ($fromEnvFile) {
        $env:OPENAI_API_KEY = $fromEnvFile
        Write-Host "Loaded OPENAI_API_KEY from $(Join-Path $PSScriptRoot '.env')."
        return
    }

    throw @"
OPENAI_API_KEY is not set and no .env file with OPENAI_API_KEY was found.

Option 1 - set for the current session:
    `$env:OPENAI_API_KEY = "sk-..."

Option 2 - persist for new sessions (Windows):
    setx OPENAI_API_KEY "sk-..."

Option 3 - add to .env at the repo root:
    OPENAI_API_KEY=sk-...
"@
}

function ConvertTo-HtmlSafe {
    param([Parameter(ValueFromPipeline)][string]$Text)

    if ($null -eq $Text) { return "" }
    return ($Text -replace '&', '&amp;' -replace '<', '&lt;' -replace '>', '&gt;' -replace '"', '&quot;')
}

function Get-PatientDemographics {
    param([Parameter(Mandatory)][int]$PatientPid)

    $sql = @"
SELECT pid, fname, mname, lname, DATE_FORMAT(DOB,'%Y-%m-%d') AS dob, sex,
       TIMESTAMPDIFF(YEAR, DOB, CURDATE()) AS age
FROM patient_data
WHERE pid = $PatientPid;
"@

    $rawOutput = & docker compose --project-name $ProjectName exec -T mysql `
        mariadb -uopenemr -popenemr openemr --batch --skip-column-names -e $sql
    if ($LASTEXITCODE -ne 0) {
        throw "Patient query failed with exit code $LASTEXITCODE."
    }

    $line = $rawOutput | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -First 1
    if (-not $line) {
        throw "Patient with pid=$PatientPid was not found. Run ./list-patients.ps1 to see available patient IDs."
    }

    $fields = $line -split "`t", 7
    if ($fields.Count -lt 7) {
        throw "Unexpected patient row shape: $line"
    }

    $fname = $fields[1]
    $mname = $fields[2]
    $lname = $fields[3]
    $dob   = $fields[4]
    $sex   = $fields[5]
    $age   = [int]$fields[6]

    $fullName = (@($fname, $mname, $lname) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }) -join ' '
    $initials = "$($fname.Substring(0,1)).$($lname.Substring(0,1))."

    return [pscustomobject]@{
        Pid       = [int]$fields[0]
        FullName  = $fullName
        Initials  = $initials
        DOB       = $dob
        Sex       = $sex
        Age       = $age
    }
}

function Invoke-OpenAILabReport {
    param(
        [Parameter(Mandatory)][string]$Initials,
        [Parameter(Mandatory)][int]$Age,
        [Parameter(Mandatory)][string]$Sex,
        [string]$PanelOverride,
        [int]$SeedHint,
        [Parameter(Mandatory)][string]$ModelName
    )

    $userMessage = "Patient initials $Initials, age $Age, sex $Sex. Generate a complete realistic lab panel with one or two mildly abnormal findings and a 1-2 sentence interpretive comment."
    if (-not [string]::IsNullOrWhiteSpace($PanelOverride)) {
        $userMessage += " Panel must be: $PanelOverride."
    }
    if ($SeedHint -ne 0) {
        $userMessage += " Use seed $SeedHint for value variation."
    }

    # OpenAI Structured Outputs strict mode supports a JSON Schema subset:
    # type, enum, anyOf, items, properties, required, additionalProperties (must be false),
    # minItems/maxItems. It does NOT support 'pattern' or 'format', so we rely on the
    # system prompt to keep LOINC codes well-formed.
    $schema = @{
        name   = "LabReport"
        strict = $true
        schema = @{
            type                 = "object"
            additionalProperties = $false
            required             = @("panelName", "loincPanel", "specimen", "collectedAt", "reportedAt", "performingLab", "results", "narrative")
            properties           = @{
                panelName     = @{ type = "string" }
                loincPanel    = @{ type = "string" }
                specimen      = @{ type = "string"; enum = @("Serum", "Plasma", "Whole blood", "Urine") }
                collectedAt   = @{ type = "string" }
                reportedAt    = @{ type = "string" }
                performingLab = @{
                    type                 = "object"
                    additionalProperties = $false
                    required             = @("name", "cliaId", "director", "address")
                    properties           = @{
                        name     = @{ type = "string" }
                        cliaId   = @{ type = "string" }
                        director = @{ type = "string" }
                        address  = @{ type = "string" }
                    }
                }
                results       = @{
                    type     = "array"
                    minItems = 1
                    items    = @{
                        type                 = "object"
                        additionalProperties = $false
                        required             = @("testName", "loinc", "value", "units", "refRange", "flag", "status", "comment")
                        properties           = @{
                            testName = @{ type = "string" }
                            loinc    = @{ type = "string" }
                            value    = @{ type = "string" }
                            units    = @{ type = "string" }
                            refRange = @{ type = "string" }
                            flag     = @{ type = "string"; enum = @("", "H", "L", "HH", "LL", "A") }
                            status   = @{ type = "string"; enum = @("final", "preliminary", "corrected") }
                            comment  = @{ type = "string" }
                        }
                    }
                }
                narrative     = @{ type = "string" }
            }
        }
    }

    $body = @{
        model           = $ModelName
        temperature     = 0.7
        messages        = @(
            @{ role = "system"; content = "You generate realistic but synthetic clinical lab reports for software demos. Values must be plausible for the patient's age and sex. Mark abnormal flags accurately by comparing the value to the reference range. LOINC codes must follow the format NNNN-N or NNNNN-N (digits-dash-digit). Do not include real PHI. Use ISO-8601 date-time strings for collectedAt and reportedAt." }
            @{ role = "user"; content = $userMessage }
        )
        response_format = @{
            type        = "json_schema"
            json_schema = $schema
        }
    }

    $bodyJson = $body | ConvertTo-Json -Depth 30 -Compress

    try {
        $response = Invoke-RestMethod -Uri "https://api.openai.com/v1/chat/completions" `
            -Method Post `
            -Headers @{ Authorization = "Bearer $($env:OPENAI_API_KEY)" } `
            -ContentType "application/json" `
            -Body $bodyJson `
            -TimeoutSec 60
    }
    catch {
        $errMsg = $_.Exception.Message
        $errBody = $null
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) {
            try {
                $parsed = $_.ErrorDetails.Message | ConvertFrom-Json
                if ($parsed.error.message) { $errBody = $parsed.error.message }
            }
            catch { $errBody = $_.ErrorDetails.Message }
        }

        if ($errBody) {
            throw "OpenAI API call failed: $errBody"
        }
        else {
            throw "OpenAI API call failed: $errMsg"
        }
    }

    $content = $response.choices[0].message.content
    if ([string]::IsNullOrWhiteSpace($content)) {
        throw "OpenAI returned an empty response."
    }

    return $content | ConvertFrom-Json
}

function Format-LabHtml {
    param(
        [Parameter(Mandatory)]$Patient,
        [Parameter(Mandatory)]$Report
    )

    $accession = "ACC-$([Guid]::NewGuid().ToString('N').Substring(0,8).ToUpper())"

    $rowsHtml = foreach ($r in $Report.results) {
        $flag = $r.flag
        $rowStyle = ""
        if ($flag -eq "H" -or $flag -eq "HH") {
            $rowStyle = ' style="color:#c00; font-weight:bold;"'
        }
        elseif ($flag -eq "L" -or $flag -eq "LL") {
            $rowStyle = ' style="color:#0066cc; font-weight:bold;"'
        }
        elseif (-not [string]::IsNullOrWhiteSpace($flag)) {
            $rowStyle = ' style="font-weight:bold;"'
        }

        @"
<tr$rowStyle>
  <td>$($r.testName | ConvertTo-HtmlSafe)<br><span class="loinc">LOINC $($r.loinc | ConvertTo-HtmlSafe)</span></td>
  <td class="num">$($r.value | ConvertTo-HtmlSafe)</td>
  <td>$($r.units | ConvertTo-HtmlSafe)</td>
  <td>$($r.refRange | ConvertTo-HtmlSafe)</td>
  <td class="num">$($r.flag | ConvertTo-HtmlSafe)</td>
  <td>$($r.status | ConvertTo-HtmlSafe)</td>
</tr>
"@
    }

    $resultsHtml = $rowsHtml -join "`n"

    $commentsHtml = ($Report.results | Where-Object { -not [string]::IsNullOrWhiteSpace($_.comment) } | ForEach-Object {
            "<div class=`"comment`"><strong>$($_.testName | ConvertTo-HtmlSafe):</strong> $($_.comment | ConvertTo-HtmlSafe)</div>"
        }) -join "`n"

    return @"
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #222; }
  .letterhead { text-align: center; border-bottom: 2px solid #333; padding-bottom: 8pt; margin-bottom: 12pt; }
  .letterhead h1 { font-size: 16pt; margin: 0 0 2pt 0; letter-spacing: 1pt; }
  .letterhead .meta { font-size: 8pt; color: #555; }
  .demobox { border: 1px solid #999; padding: 6pt; margin-bottom: 10pt; }
  .demobox table { width: 100%; border-collapse: collapse; }
  .demobox td { padding: 2pt 6pt; vertical-align: top; }
  .demobox .label { font-weight: bold; color: #555; width: 22%; }
  h2 { font-size: 11pt; border-bottom: 1px solid #333; padding-bottom: 2pt; margin-top: 12pt; margin-bottom: 4pt; }
  table.results { width: 100%; border-collapse: collapse; margin-top: 4pt; }
  table.results th { background: #eee; text-align: left; padding: 4pt 6pt; border: 1px solid #999; font-size: 9pt; }
  table.results td { padding: 4pt 6pt; border: 1px solid #ccc; vertical-align: top; }
  table.results td.num { text-align: right; font-family: monospace; }
  .loinc { font-size: 7pt; color: #888; }
  .narrative { margin-top: 10pt; font-style: italic; padding: 6pt; background: #f7f7f0; border-left: 3px solid #999; }
  .comment { margin-top: 4pt; font-size: 9pt; }
  .footer { margin-top: 18pt; padding-top: 6pt; border-top: 1px solid #999; font-size: 7pt; color: #777; text-align: center; }
  .signature { margin-top: 18pt; font-size: 9pt; }
  .signature .line { display: inline-block; width: 220pt; border-bottom: 1px solid #333; margin-right: 4pt; }
</style>
</head>
<body>

<div class="letterhead">
  <h1>$($Report.performingLab.name | ConvertTo-HtmlSafe)</h1>
  <div class="meta">
    $($Report.performingLab.address | ConvertTo-HtmlSafe)<br>
    CLIA: $($Report.performingLab.cliaId | ConvertTo-HtmlSafe) &nbsp;&middot;&nbsp; Lab Director: $($Report.performingLab.director | ConvertTo-HtmlSafe)
  </div>
</div>

<div class="demobox">
  <table>
    <tr>
      <td class="label">Patient:</td>
      <td>$($Patient.FullName | ConvertTo-HtmlSafe)</td>
      <td class="label">MRN:</td>
      <td>$($Patient.Pid)</td>
    </tr>
    <tr>
      <td class="label">DOB:</td>
      <td>$($Patient.DOB | ConvertTo-HtmlSafe) (age $($Patient.Age))</td>
      <td class="label">Sex:</td>
      <td>$($Patient.Sex | ConvertTo-HtmlSafe)</td>
    </tr>
    <tr>
      <td class="label">Accession:</td>
      <td>$accession</td>
      <td class="label">Ordering provider:</td>
      <td>Dr. Demo, MD</td>
    </tr>
    <tr>
      <td class="label">Specimen:</td>
      <td>$($Report.specimen | ConvertTo-HtmlSafe)</td>
      <td class="label">Panel:</td>
      <td>$($Report.panelName | ConvertTo-HtmlSafe) (LOINC $($Report.loincPanel | ConvertTo-HtmlSafe))</td>
    </tr>
    <tr>
      <td class="label">Collected:</td>
      <td>$($Report.collectedAt | ConvertTo-HtmlSafe)</td>
      <td class="label">Reported:</td>
      <td>$($Report.reportedAt | ConvertTo-HtmlSafe)</td>
    </tr>
  </table>
</div>

<h2>Results</h2>
<table class="results">
  <thead>
    <tr>
      <th>Test</th><th style="text-align:right;">Result</th><th>Units</th><th>Reference Range</th><th style="text-align:right;">Flag</th><th>Status</th>
    </tr>
  </thead>
  <tbody>
$resultsHtml
  </tbody>
</table>

$(if ($commentsHtml) { "<h2>Comments</h2>$commentsHtml" })

<h2>Interpretive Note</h2>
<div class="narrative">$($Report.narrative | ConvertTo-HtmlSafe)</div>

<div class="signature">
  Reviewed by: <span class="line">&nbsp;</span> Date: <span class="line">&nbsp;</span>
</div>

<div class="footer">
  Synthetic data for software demonstration only — not for clinical use. LOINC codes not validated.
</div>

</body>
</html>
"@
}

function Invoke-MpdfRender {
    param(
        [Parameter(Mandatory)][string]$Html,
        [Parameter(Mandatory)][string]$OutputPath
    )

    $stamp = [Guid]::NewGuid().ToString('N').Substring(0, 8)
    $htmlInContainer = "/tmp/lab-$stamp.html"
    $pdfInContainer = "/tmp/lab-$stamp.pdf"
    $phpInContainer = "/tmp/lab-render-$stamp.php"

    $hostHtml = Join-Path ([IO.Path]::GetTempPath()) "lab-$stamp.html"
    $hostPhp = Join-Path ([IO.Path]::GetTempPath()) "lab-render-$stamp.php"

    $phpShim = @'
<?php
require '/var/www/localhost/htdocs/openemr/vendor/autoload.php';
$html = file_get_contents($argv[1]);
$pdf = new \Mpdf\Mpdf([
    'default_font' => 'dejavusans',
    'format'       => 'Letter',
    'tempDir'      => '/tmp/mpdf',
]);
$pdf->WriteHTML($html);
$pdf->Output($argv[2], \Mpdf\Output\Destination::FILE);
'@

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($hostHtml, $Html, $utf8NoBom)
    [IO.File]::WriteAllText($hostPhp, $phpShim, $utf8NoBom)

    try {
        & docker compose --project-name $ProjectName cp $hostHtml "openemr:$htmlInContainer" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "docker compose cp (html in) failed with exit code $LASTEXITCODE." }

        & docker compose --project-name $ProjectName cp $hostPhp "openemr:$phpInContainer" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "docker compose cp (php in) failed with exit code $LASTEXITCODE." }

        & docker compose --project-name $ProjectName exec -T openemr `
            sh -c "mkdir -p /tmp/mpdf && php $phpInContainer $htmlInContainer $pdfInContainer"
        if ($LASTEXITCODE -ne 0) { throw "mPDF rendering failed with exit code $LASTEXITCODE." }

        & docker compose --project-name $ProjectName cp "openemr:$pdfInContainer" $OutputPath | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "docker compose cp (pdf out) failed with exit code $LASTEXITCODE." }
    }
    finally {
        & docker compose --project-name $ProjectName exec -T openemr `
            rm -f $htmlInContainer $pdfInContainer $phpInContainer 2>$null | Out-Null

        Remove-Item -Path $hostHtml, $hostPhp -Force -ErrorAction SilentlyContinue
    }
}

# --- Main ---

Confirm-DockerCompose
Confirm-MysqlContainerRunning
Confirm-OpenemrContainerRunning
Confirm-OpenAIKey

Write-Host "Fetching demographics for patient pid=$PatientId..."
$patient = Get-PatientDemographics -PatientPid $PatientId

if ([string]::IsNullOrWhiteSpace($OutFile)) {
    $reportsDir = Join-Path $PSScriptRoot "lab-reports"
    if (-not (Test-Path $reportsDir)) {
        New-Item -ItemType Directory -Path $reportsDir -Force | Out-Null
    }
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutFile = Join-Path $reportsDir "lab-$($patient.Pid)-$timestamp.pdf"
}

Write-Host "Calling OpenAI ($Model) for synthetic lab report..."
$report = Invoke-OpenAILabReport `
    -Initials $patient.Initials `
    -Age $patient.Age `
    -Sex $patient.Sex `
    -PanelOverride $Panel `
    -SeedHint $Seed `
    -ModelName $Model

Write-Host "Building HTML and rendering via mPDF inside the openemr container..."
$html = Format-LabHtml -Patient $patient -Report $report
Invoke-MpdfRender -Html $html -OutputPath $OutFile

$abnormalCount = @($report.results | Where-Object { -not [string]::IsNullOrWhiteSpace($_.flag) }).Count

Write-Host ""
Write-Host "Lab report generated:"
Write-Host "  File:     $OutFile"
Write-Host "  Patient:  $($patient.FullName) (pid $($patient.Pid))"
Write-Host "  Panel:    $($report.panelName)"
Write-Host "  Results:  $(@($report.results).Count) total, $abnormalCount abnormal"
