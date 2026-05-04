# generate-lab-pdf.ps1 — synthetic lab PDF generator
#
# Generates a realistic-looking lab report PDF for the given patient and
# renders it via OpenEMR's bundled mPDF inside the running 'openemr' container.
#
# Two modes:
#   Default — content from OpenAI Structured Outputs (most realistic, varied).
#             PII contract: only patient INITIALS, AGE, and SEX leave the host.
#             Requires OPENAI_API_KEY (env var or .env at repo root).
#   -Offline — content from hard-coded panel templates (CBC, BMP, CMP, Lipid,
#              A1c, TSH) with values drawn from realistic adult reference
#              ranges. No network calls, no API key required.
#              Pass -Seed N for reproducible output.
#
# The PDF is saved to disk only — it is NOT inserted into the OpenEMR documents
# or procedure_* tables.

param(
    [Parameter(Mandatory)][int]$PatientId,
    [string]$ProjectName = "openemr",
    [string]$Panel,
    [string]$Model = "gpt-4o-mini",
    [string]$OutFile,
    [int]$Seed,
    [switch]$Offline
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

# --- Offline mode: panel templates and report generator ---
#
# Each test entry: Name, Loinc, Units, Low, High (adult reference range), Decimals.
# Reference ranges are unified across sex; pediatric patients may show abnormal flags
# that wouldn't be flagged by an age-aware lab — fine for synthetic demo data.

$Panels = @{
    "CBC"   = @{
        Name       = "Complete Blood Count"
        LoincPanel = "58410-2"
        Specimen   = "Whole blood"
        Tests      = @(
            @{ Name = "WBC"; Loinc = "6690-2"; Units = "10*3/uL"; Low = 4.0; High = 11.0; Decimals = 1 }
            @{ Name = "RBC"; Loinc = "789-8"; Units = "10*6/uL"; Low = 4.2; High = 5.9; Decimals = 2 }
            @{ Name = "Hemoglobin"; Loinc = "718-7"; Units = "g/dL"; Low = 12.0; High = 17.5; Decimals = 1 }
            @{ Name = "Hematocrit"; Loinc = "4544-3"; Units = "%"; Low = 36.0; High = 52.0; Decimals = 1 }
            @{ Name = "Platelets"; Loinc = "777-3"; Units = "10*3/uL"; Low = 150; High = 400; Decimals = 0 }
            @{ Name = "MCV"; Loinc = "787-2"; Units = "fL"; Low = 80.0; High = 100.0; Decimals = 1 }
            @{ Name = "MCH"; Loinc = "785-6"; Units = "pg"; Low = 27.0; High = 33.0; Decimals = 1 }
            @{ Name = "MCHC"; Loinc = "786-4"; Units = "g/dL"; Low = 32.0; High = 36.0; Decimals = 1 }
        )
    }
    "BMP"   = @{
        Name       = "Basic Metabolic Panel"
        LoincPanel = "51990-0"
        Specimen   = "Serum"
        Tests      = @(
            @{ Name = "Sodium"; Loinc = "2951-2"; Units = "mmol/L"; Low = 136; High = 145; Decimals = 0 }
            @{ Name = "Potassium"; Loinc = "2823-3"; Units = "mmol/L"; Low = 3.5; High = 5.1; Decimals = 1 }
            @{ Name = "Chloride"; Loinc = "2075-0"; Units = "mmol/L"; Low = 98; High = 107; Decimals = 0 }
            @{ Name = "CO2"; Loinc = "2028-9"; Units = "mmol/L"; Low = 22; High = 29; Decimals = 0 }
            @{ Name = "BUN"; Loinc = "3094-0"; Units = "mg/dL"; Low = 7; High = 20; Decimals = 0 }
            @{ Name = "Creatinine"; Loinc = "2160-0"; Units = "mg/dL"; Low = 0.6; High = 1.3; Decimals = 2 }
            @{ Name = "Glucose"; Loinc = "2345-7"; Units = "mg/dL"; Low = 70; High = 99; Decimals = 0 }
            @{ Name = "Calcium"; Loinc = "17861-6"; Units = "mg/dL"; Low = 8.6; High = 10.2; Decimals = 1 }
        )
    }
    "CMP"   = @{
        Name       = "Comprehensive Metabolic Panel"
        LoincPanel = "24323-8"
        Specimen   = "Serum"
        Tests      = @(
            @{ Name = "Sodium"; Loinc = "2951-2"; Units = "mmol/L"; Low = 136; High = 145; Decimals = 0 }
            @{ Name = "Potassium"; Loinc = "2823-3"; Units = "mmol/L"; Low = 3.5; High = 5.1; Decimals = 1 }
            @{ Name = "Chloride"; Loinc = "2075-0"; Units = "mmol/L"; Low = 98; High = 107; Decimals = 0 }
            @{ Name = "CO2"; Loinc = "2028-9"; Units = "mmol/L"; Low = 22; High = 29; Decimals = 0 }
            @{ Name = "BUN"; Loinc = "3094-0"; Units = "mg/dL"; Low = 7; High = 20; Decimals = 0 }
            @{ Name = "Creatinine"; Loinc = "2160-0"; Units = "mg/dL"; Low = 0.6; High = 1.3; Decimals = 2 }
            @{ Name = "Glucose"; Loinc = "2345-7"; Units = "mg/dL"; Low = 70; High = 99; Decimals = 0 }
            @{ Name = "Calcium"; Loinc = "17861-6"; Units = "mg/dL"; Low = 8.6; High = 10.2; Decimals = 1 }
            @{ Name = "Total Protein"; Loinc = "2885-2"; Units = "g/dL"; Low = 6.0; High = 8.3; Decimals = 1 }
            @{ Name = "Albumin"; Loinc = "1751-7"; Units = "g/dL"; Low = 3.5; High = 5.0; Decimals = 1 }
            @{ Name = "Total Bilirubin"; Loinc = "1975-2"; Units = "mg/dL"; Low = 0.1; High = 1.2; Decimals = 1 }
            @{ Name = "Alkaline Phosphatase"; Loinc = "6768-6"; Units = "U/L"; Low = 44; High = 147; Decimals = 0 }
            @{ Name = "ALT"; Loinc = "1742-6"; Units = "U/L"; Low = 7; High = 56; Decimals = 0 }
            @{ Name = "AST"; Loinc = "1920-8"; Units = "U/L"; Low = 10; High = 40; Decimals = 0 }
        )
    }
    "Lipid" = @{
        Name       = "Lipid Panel"
        LoincPanel = "57698-3"
        Specimen   = "Serum"
        Tests      = @(
            @{ Name = "Total Cholesterol"; Loinc = "2093-3"; Units = "mg/dL"; Low = 100; High = 199; Decimals = 0 }
            @{ Name = "Triglycerides"; Loinc = "2571-8"; Units = "mg/dL"; Low = 30; High = 149; Decimals = 0 }
            @{ Name = "HDL Cholesterol"; Loinc = "2085-9"; Units = "mg/dL"; Low = 40; High = 100; Decimals = 0 }
            @{ Name = "LDL Cholesterol"; Loinc = "13457-7"; Units = "mg/dL"; Low = 0; High = 99; Decimals = 0 }
        )
    }
    "A1c"   = @{
        Name       = "Hemoglobin A1c"
        LoincPanel = "4548-4"
        Specimen   = "Whole blood"
        Tests      = @(
            @{ Name = "Hemoglobin A1c"; Loinc = "4548-4"; Units = "%"; Low = 4.0; High = 5.6; Decimals = 1 }
        )
    }
    "TSH"   = @{
        Name       = "Thyroid Stimulating Hormone"
        LoincPanel = "11580-8"
        Specimen   = "Serum"
        Tests      = @(
            @{ Name = "TSH"; Loinc = "3016-3"; Units = "mIU/L"; Low = 0.4; High = 4.5; Decimals = 2 }
        )
    }
}

$PerformingLabs = @(
    @{ Name = "Demoware Clinical Laboratories"; CliaId = "12D9876543"; Director = "Dr. Demo Director, MD"; Address = "100 Lab Way, Demo City, CA 90210" }
    @{ Name = "Synthetic Pathology Services"; CliaId = "09D7654321"; Director = "Dr. Pat Synth, MD, PhD"; Address = "500 Beaker Blvd, Reagent City, TX 77001" }
    @{ Name = "Sample Diagnostics, Inc."; CliaId = "05D1234567"; Director = "Dr. Sam Sample, MD"; Address = "200 Test Plaza, Sample Town, NY 10001" }
    @{ Name = "Mockingbird Reference Lab"; CliaId = "33D5551212"; Director = "Dr. Quinn Mockler, MD"; Address = "42 Cytology Ct, Specimen IL 60601" }
)

function New-LabReport {
    param(
        [Parameter(Mandatory)]$Patient,
        [string]$PanelKey,
        [Parameter(Mandatory)][System.Random]$Rng
    )

    if (-not [string]::IsNullOrWhiteSpace($PanelKey)) {
        if (-not $Panels.ContainsKey($PanelKey)) {
            $validKeys = ($Panels.Keys | Sort-Object) -join ", "
            throw "Unknown panel '$PanelKey' for offline mode. Valid keys: $validKeys"
        }
    }
    else {
        $sortedKeys = @($Panels.Keys | Sort-Object)
        $PanelKey = $sortedKeys[$Rng.Next(0, $sortedKeys.Count)]
    }
    $template = $Panels[$PanelKey]
    $tests = $template.Tests

    # Pick 1-2 tests to be intentionally abnormal so the demo shows realistic flagging.
    $abnormalCount = $Rng.Next(1, 3)
    if ($tests.Count -eq 1) { $abnormalCount = 1 }
    $abnormalIndices = New-Object System.Collections.Generic.HashSet[int]
    while ($abnormalIndices.Count -lt $abnormalCount) {
        [void]$abnormalIndices.Add($Rng.Next(0, $tests.Count))
    }

    $results = for ($i = 0; $i -lt $tests.Count; $i++) {
        $t = $tests[$i]
        $low = [double]$t.Low
        $high = [double]$t.High
        $range = $high - $low
        $decimals = [int]$t.Decimals
        $forceAbnormal = $abnormalIndices.Contains($i)

        if ($forceAbnormal) {
            $delta = $range * (0.10 + $Rng.NextDouble() * 0.30)
            $value = if ($Rng.Next(0, 2) -eq 0) { $low - $delta } else { $high + $delta }
            if ($value -lt 0 -and $low -ge 0) { $value = [math]::Max(0, $low * 0.5) }
        }
        else {
            $center = ($low + $high) / 2.0
            $jitter = $range * 0.30
            $value = $center + ($Rng.NextDouble() * 2 - 1) * $jitter
            if ($value -lt $low) { $value = $low + $range * 0.05 }
            if ($value -gt $high) { $value = $high - $range * 0.05 }
        }

        $rounded = [math]::Round($value, $decimals)
        $invariant = [Globalization.CultureInfo]::InvariantCulture
        $valueText = $rounded.ToString("F$decimals", $invariant)

        $flag = ""
        if ($rounded -lt $low) { $flag = "L" }
        elseif ($rounded -gt $high) { $flag = "H" }
        if ($flag -eq "L" -and ($low - $rounded) -gt $range * 0.5) { $flag = "LL" }
        if ($flag -eq "H" -and ($rounded - $high) -gt $range * 0.5) { $flag = "HH" }

        $rangeFmt = "F$decimals"
        $rangeText = "$($low.ToString($rangeFmt, $invariant)) - $($high.ToString($rangeFmt, $invariant))"

        $comment = ""
        if ($flag -eq "H" -or $flag -eq "HH") {
            $comment = "Above reference range. Recommend clinical correlation."
        }
        elseif ($flag -eq "L" -or $flag -eq "LL") {
            $comment = "Below reference range. Recommend clinical correlation."
        }

        [pscustomobject]@{
            testName = $t.Name
            loinc    = $t.Loinc
            value    = $valueText
            units    = $t.Units
            refRange = $rangeText
            flag     = $flag
            status   = "final"
            comment  = $comment
        }
    }

    $abnormalDescriptions = @(
        $results | Where-Object { -not [string]::IsNullOrWhiteSpace($_.flag) } | ForEach-Object {
            $direction = switch -Regex ($_.flag) {
                'HH' { "markedly elevated" }
                'LL' { "markedly decreased" }
                'H' { "elevated" }
                'L' { "decreased" }
                default { "abnormal" }
            }
            "$($_.testName) $direction"
        }
    )

    if ($abnormalDescriptions.Count -eq 0) {
        $narrative = "All values within reference range. No clinically significant findings on this $($template.Name)."
    }
    else {
        $narrative = "$($template.Name) shows: $($abnormalDescriptions -join '; '). Recommend clinical correlation and follow-up testing as indicated."
    }

    $lab = $PerformingLabs[$Rng.Next(0, $PerformingLabs.Count)]
    $collectedHoursAgo = $Rng.Next(20, 48)
    $reportedHoursAgo = $Rng.Next(0, [math]::Max(1, $collectedHoursAgo - 4))
    $collected = (Get-Date).AddHours(-$collectedHoursAgo)
    $reported = (Get-Date).AddHours(-$reportedHoursAgo)
    $isoFormat = "yyyy-MM-ddTHH:mm:ss"

    return [pscustomobject]@{
        panelName     = $template.Name
        loincPanel    = $template.LoincPanel
        specimen      = $template.Specimen
        collectedAt   = $collected.ToString($isoFormat)
        reportedAt    = $reported.ToString($isoFormat)
        performingLab = [pscustomobject]@{
            name     = $lab.Name
            cliaId   = $lab.CliaId
            director = $lab.Director
            address  = $lab.Address
        }
        results       = @($results)
        narrative     = $narrative
    }
}

# --- LLM mode: OpenAI Structured Outputs ---

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
if (-not $Offline) {
    Confirm-OpenAIKey
}

Write-Host "Fetching demographics for patient pid=$PatientId..."
$patient = Get-PatientDemographics -PatientPid $PatientId

if ([string]::IsNullOrWhiteSpace($OutFile)) {
    $reportsDir = Join-Path $PSScriptRoot "lab-reports"
    if (-not (Test-Path $reportsDir)) {
        New-Item -ItemType Directory -Path $reportsDir -Force | Out-Null
    }
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $suffix = if ($Offline) { "-offline" } else { "" }
    $OutFile = Join-Path $reportsDir "lab-$($patient.Pid)-$timestamp$suffix.pdf"
}

if ($Offline) {
    if ($PSBoundParameters.ContainsKey('Seed')) {
        $rngSeed = $Seed
    }
    else {
        $rngSeed = Get-Random -Minimum 1 -Maximum 1000000
    }
    $rng = [System.Random]::new($rngSeed)

    $panelLabel = if ([string]::IsNullOrWhiteSpace($Panel)) { "(random panel)" } else { $Panel }
    Write-Host "Generating synthetic $panelLabel report (offline mode, seed $rngSeed)..."
    $report = New-LabReport -Patient $patient -PanelKey $Panel -Rng $rng
}
else {
    Write-Host "Calling OpenAI ($Model) for synthetic lab report..."
    $report = Invoke-OpenAILabReport `
        -Initials $patient.Initials `
        -Age $patient.Age `
        -Sex $patient.Sex `
        -PanelOverride $Panel `
        -SeedHint $Seed `
        -ModelName $Model
}

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
if ($Offline) {
    Write-Host "  Seed:     $rngSeed (use -Seed $rngSeed to reproduce)"
}
