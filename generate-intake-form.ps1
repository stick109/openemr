# generate-intake-form.ps1 — synthetic intake-form PDF generator
#
# Generates a realistic-looking patient intake-form PDF for the given patient
# by calling OpenAI for the form content (Structured Outputs) and rendering
# via OpenEMR's bundled mPDF inside the running 'openemr' container.
#
# Three FormTypes are supported:
#   Demographics    - new patient registration form (name/dob/address/insurance)
#   MedicalHistory  - past conditions, surgeries, meds, allergies, family/social
#   Consent         - HIPAA acknowledgment + consent for treatment
#
# PII contract: only patient INITIALS, AGE, and SEX are sent to OpenAI.
# Full names, DOB, address, phone, and SSN never leave the host. The LLM
# fabricates the demographic data printed on the PDF; the host patient is
# only used as a flavor seed (initials/age/sex) so the form looks plausible.
#
# The PDF is saved to disk only - it is NOT inserted into the OpenEMR documents
# or patient_data tables. Use the in-app "Upload Intake Form" UI to ingest it.

param(
    [Parameter(Mandatory)][int]$PatientId,
    [Parameter(Mandatory)]
    [ValidateSet('Demographics', 'MedicalHistory', 'Consent')]
    [string]$FormType,
    [string]$ProjectName = "openemr",
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
    $lname = $fields[3]
    $sex = $fields[5]
    $age = [int]$fields[6]

    if ([string]::IsNullOrWhiteSpace($fname) -or [string]::IsNullOrWhiteSpace($lname)) {
        throw "Patient pid=$PatientPid is missing first or last name; cannot derive initials."
    }

    $initials = "$($fname.Substring(0,1)).$($lname.Substring(0,1))."

    return [pscustomobject]@{
        Pid      = [int]$fields[0]
        Initials = $initials
        Sex      = $sex
        Age      = $age
    }
}

# ----- Per-form-type schemas, prompts, and templates -----------------------

function Get-DemographicsSchema {
    return @{
        name   = "DemographicsForm"
        strict = $true
        schema = @{
            type                 = "object"
            additionalProperties = $false
            required             = @("firstName", "lastName", "dob", "sex", "address", "phone", "email", "emergencyContact", "insurance")
            properties           = @{
                firstName        = @{ type = "string" }
                lastName         = @{ type = "string" }
                dob              = @{ type = "string" }
                sex              = @{ type = "string"; enum = @("Male", "Female", "Other", "Prefer not to say") }
                address          = @{
                    type                 = "object"
                    additionalProperties = $false
                    required             = @("street", "city", "state", "zip")
                    properties           = @{
                        street = @{ type = "string" }
                        city   = @{ type = "string" }
                        state  = @{ type = "string" }
                        zip    = @{ type = "string" }
                    }
                }
                phone            = @{ type = "string" }
                email            = @{ type = "string" }
                emergencyContact = @{
                    type                 = "object"
                    additionalProperties = $false
                    required             = @("name", "relationship", "phone")
                    properties           = @{
                        name         = @{ type = "string" }
                        relationship = @{ type = "string" }
                        phone        = @{ type = "string" }
                    }
                }
                insurance        = @{
                    type                 = "object"
                    additionalProperties = $false
                    required             = @("carrier", "memberId", "group", "planType")
                    properties           = @{
                        carrier  = @{ type = "string" }
                        memberId = @{ type = "string" }
                        group    = @{ type = "string" }
                        planType = @{ type = "string"; enum = @("PPO", "HMO", "EPO", "POS", "HDHP", "Medicare", "Medicaid", "Self-Pay") }
                    }
                }
            }
        }
    }
}

function Get-MedicalHistorySchema {
    return @{
        name   = "MedicalHistoryForm"
        strict = $true
        schema = @{
            type                 = "object"
            additionalProperties = $false
            required             = @("conditions", "surgeries", "medications", "allergies", "familyHistory", "social")
            properties           = @{
                conditions     = @{
                    type     = "array"
                    minItems = 0
                    items    = @{
                        type                 = "object"
                        additionalProperties = $false
                        required             = @("name", "yearOfOnset", "status")
                        properties           = @{
                            name        = @{ type = "string" }
                            yearOfOnset = @{ type = "string" }
                            status      = @{ type = "string"; enum = @("Active", "Resolved", "Chronic", "In remission") }
                        }
                    }
                }
                surgeries      = @{
                    type     = "array"
                    minItems = 0
                    items    = @{
                        type                 = "object"
                        additionalProperties = $false
                        required             = @("procedure", "year", "facility")
                        properties           = @{
                            procedure = @{ type = "string" }
                            year      = @{ type = "string" }
                            facility  = @{ type = "string" }
                        }
                    }
                }
                medications    = @{
                    type     = "array"
                    minItems = 0
                    items    = @{
                        type                 = "object"
                        additionalProperties = $false
                        required             = @("name", "dose", "frequency", "indication")
                        properties           = @{
                            name       = @{ type = "string" }
                            dose       = @{ type = "string" }
                            frequency  = @{ type = "string" }
                            indication = @{ type = "string" }
                        }
                    }
                }
                allergies      = @{
                    type     = "array"
                    minItems = 0
                    items    = @{
                        type                 = "object"
                        additionalProperties = $false
                        required             = @("allergen", "reaction", "severity")
                        properties           = @{
                            allergen = @{ type = "string" }
                            reaction = @{ type = "string" }
                            severity = @{ type = "string"; enum = @("Mild", "Moderate", "Severe", "Life-threatening") }
                        }
                    }
                }
                familyHistory  = @{
                    type     = "array"
                    minItems = 0
                    items    = @{
                        type                 = "object"
                        additionalProperties = $false
                        required             = @("relative", "condition", "ageOfOnset")
                        properties           = @{
                            relative   = @{ type = "string" }
                            condition  = @{ type = "string" }
                            ageOfOnset = @{ type = "string" }
                        }
                    }
                }
                social         = @{
                    type                 = "object"
                    additionalProperties = $false
                    required             = @("smoking", "alcohol", "drugs")
                    properties           = @{
                        smoking = @{ type = "string" }
                        alcohol = @{ type = "string" }
                        drugs   = @{ type = "string" }
                    }
                }
            }
        }
    }
}

function Get-ConsentSchema {
    return @{
        name   = "ConsentForm"
        strict = $true
        schema = @{
            type                 = "object"
            additionalProperties = $false
            required             = @("patientName", "signatureDate", "clinicName", "clinicAddress")
            properties           = @{
                patientName   = @{ type = "string" }
                signatureDate = @{ type = "string" }
                clinicName    = @{ type = "string" }
                clinicAddress = @{ type = "string" }
            }
        }
    }
}

function Get-FormTypePrompt {
    param(
        [Parameter(Mandatory)][string]$FormTypeName,
        [Parameter(Mandatory)][string]$Initials,
        [Parameter(Mandatory)][int]$Age,
        [Parameter(Mandatory)][string]$Sex,
        [int]$SeedHint
    )

    $base = "Patient initials $Initials, age $Age, sex $Sex."
    if ($SeedHint -ne 0) {
        $base += " Use seed $SeedHint for value variation."
    }

    switch ($FormTypeName) {
        'Demographics' {
            $sys = "You generate realistic but synthetic patient registration data for software demos. Generate a complete demographics record consistent with the patient's age and sex hints. Use plausible US addresses (real city/state/ZIP combinations are fine, but the specific street address must be fictitious). Phone numbers must use the 555 exchange (e.g. 555-0100 to 555-0199) so they cannot dial real lines. Email addresses must use the example.com or example.org domain. Use ISO-8601 (YYYY-MM-DD) for dob. Pick an emergency contact relationship that is plausible (Spouse, Parent, Sibling, Adult Child, Friend). Insurance carrier must be a well-known US carrier or 'Self-Pay'. The dob you produce must be consistent with the supplied age (give or take one year)."
            $user = "$base Generate a complete demographics + insurance registration."
            return @{ system = $sys; user = $user }
        }
        'MedicalHistory' {
            $sys = "You generate realistic but synthetic medical history data for software demos. Produce 1-4 chronic conditions, 0-3 prior surgeries, 0-5 current medications, 0-3 allergies, 1-4 family-history entries, and a social-history block (smoking/alcohol/drugs). Conditions/surgeries/medications/family-history should be plausible for the patient's age and sex. Years should be plausible four-digit values (e.g. '2018'). Medication doses use SI/US units (mg, mcg, units). Frequencies use standard sigs (QD, BID, TID, PRN, q6h, etc.). Allergy reactions are short ('hives', 'anaphylaxis', 'GI upset'). Social-history fields are short free text ('Never smoker', '1-2 drinks per week', 'Denies recreational drug use')."
            $user = "$base Generate a complete medical-history questionnaire."
            return @{ system = $sys; user = $user }
        }
        'Consent' {
            $sys = "You generate realistic but synthetic consent-form metadata for software demos. The boilerplate consent text is fixed and supplied by the renderer; you only produce the patient name (first + last), signature date (ISO-8601 YYYY-MM-DD within the last 30 days), and a fictional clinic name and full address. Clinic name should sound like a real US primary-care practice (e.g. 'Cedar Ridge Family Medicine'). Address should be plausible US format (street, city, state, ZIP)."
            $user = "$base Generate a signed-consent envelope (patient name, signature date, clinic identity)."
            return @{ system = $sys; user = $user }
        }
    }

    throw "Unknown form type: $FormTypeName"
}

function Get-SchemaForFormType {
    param([Parameter(Mandatory)][string]$FormTypeName)

    switch ($FormTypeName) {
        'Demographics' { return Get-DemographicsSchema }
        'MedicalHistory' { return Get-MedicalHistorySchema }
        'Consent' { return Get-ConsentSchema }
    }

    throw "Unknown form type: $FormTypeName"
}

function Invoke-OpenAIIntakeForm {
    param(
        [Parameter(Mandatory)][string]$Initials,
        [Parameter(Mandatory)][int]$Age,
        [Parameter(Mandatory)][string]$Sex,
        [Parameter(Mandatory)][string]$FormTypeName,
        [int]$SeedHint,
        [Parameter(Mandatory)][string]$ModelName
    )

    $prompt = Get-FormTypePrompt -FormTypeName $FormTypeName -Initials $Initials -Age $Age -Sex $Sex -SeedHint $SeedHint
    $schema = Get-SchemaForFormType -FormTypeName $FormTypeName

    $body = @{
        model           = $ModelName
        temperature     = 0.7
        messages        = @(
            @{ role = "system"; content = $prompt.system }
            @{ role = "user"; content = $prompt.user }
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

# ----- HTML templates ------------------------------------------------------

function Get-CommonStyles {
    return @"
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #222; }
  .letterhead { text-align: center; border-bottom: 2px solid #333; padding-bottom: 8pt; margin-bottom: 12pt; }
  .letterhead h1 { font-size: 16pt; margin: 0 0 2pt 0; letter-spacing: 1pt; }
  .letterhead .meta { font-size: 8pt; color: #555; }
  h2 { font-size: 11pt; border-bottom: 1px solid #333; padding-bottom: 2pt; margin-top: 12pt; margin-bottom: 4pt; }
  h3 { font-size: 10pt; margin-top: 10pt; margin-bottom: 4pt; color: #444; }
  table.fields { width: 100%; border-collapse: collapse; }
  table.fields td { padding: 3pt 6pt; vertical-align: top; }
  table.fields td.label { font-weight: bold; color: #555; width: 28%; border-bottom: 1px dotted #999; }
  table.fields td.value { border-bottom: 1px solid #999; }
  table.checklist { width: 100%; border-collapse: collapse; margin-top: 4pt; }
  table.checklist th { background: #eee; text-align: left; padding: 4pt 6pt; border: 1px solid #999; font-size: 9pt; }
  table.checklist td { padding: 4pt 6pt; border: 1px solid #ccc; vertical-align: top; }
  .empty { color: #999; font-style: italic; }
  .signature { margin-top: 24pt; font-size: 9pt; }
  .signature .line { display: inline-block; width: 220pt; border-bottom: 1px solid #333; margin-right: 4pt; }
  .footer { margin-top: 18pt; padding-top: 6pt; border-top: 1px solid #999; font-size: 7pt; color: #777; text-align: center; }
  .consent-body { line-height: 1.5; text-align: justify; }
  .consent-body p { margin: 6pt 0; }
  .consent-body ol { margin: 4pt 0 4pt 20pt; padding: 0; }
  .consent-body li { margin-bottom: 4pt; }
  .cursive { font-family: 'Times New Roman', serif; font-style: italic; font-size: 16pt; }
"@
}

function Format-DemographicsHtml {
    param(
        [Parameter(Mandatory)]$Form
    )

    $styles = Get-CommonStyles

    $signatureDate = (Get-Date).ToString("yyyy-MM-dd")

    return @"
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
$styles
</style>
</head>
<body>

<div class="letterhead">
  <h1>NEW PATIENT REGISTRATION</h1>
  <div class="meta">Please fill in all fields. Information will be kept confidential per HIPAA.</div>
</div>

<h2>Patient Information</h2>
<table class="fields">
  <tr>
    <td class="label">First Name:</td>
    <td class="value">$($Form.firstName | ConvertTo-HtmlSafe)</td>
    <td class="label">Last Name:</td>
    <td class="value">$($Form.lastName | ConvertTo-HtmlSafe)</td>
  </tr>
  <tr>
    <td class="label">Date of Birth:</td>
    <td class="value">$($Form.dob | ConvertTo-HtmlSafe)</td>
    <td class="label">Sex:</td>
    <td class="value">$($Form.sex | ConvertTo-HtmlSafe)</td>
  </tr>
</table>

<h2>Contact Information</h2>
<table class="fields">
  <tr>
    <td class="label">Street Address:</td>
    <td class="value" colspan="3">$($Form.address.street | ConvertTo-HtmlSafe)</td>
  </tr>
  <tr>
    <td class="label">City:</td>
    <td class="value">$($Form.address.city | ConvertTo-HtmlSafe)</td>
    <td class="label">State / ZIP:</td>
    <td class="value">$($Form.address.state | ConvertTo-HtmlSafe) &nbsp; $($Form.address.zip | ConvertTo-HtmlSafe)</td>
  </tr>
  <tr>
    <td class="label">Phone:</td>
    <td class="value">$($Form.phone | ConvertTo-HtmlSafe)</td>
    <td class="label">Email:</td>
    <td class="value">$($Form.email | ConvertTo-HtmlSafe)</td>
  </tr>
</table>

<h2>Emergency Contact</h2>
<table class="fields">
  <tr>
    <td class="label">Name:</td>
    <td class="value">$($Form.emergencyContact.name | ConvertTo-HtmlSafe)</td>
    <td class="label">Relationship:</td>
    <td class="value">$($Form.emergencyContact.relationship | ConvertTo-HtmlSafe)</td>
  </tr>
  <tr>
    <td class="label">Phone:</td>
    <td class="value" colspan="3">$($Form.emergencyContact.phone | ConvertTo-HtmlSafe)</td>
  </tr>
</table>

<h2>Insurance Information</h2>
<table class="fields">
  <tr>
    <td class="label">Carrier:</td>
    <td class="value">$($Form.insurance.carrier | ConvertTo-HtmlSafe)</td>
    <td class="label">Plan Type:</td>
    <td class="value">$($Form.insurance.planType | ConvertTo-HtmlSafe)</td>
  </tr>
  <tr>
    <td class="label">Member ID:</td>
    <td class="value">$($Form.insurance.memberId | ConvertTo-HtmlSafe)</td>
    <td class="label">Group #:</td>
    <td class="value">$($Form.insurance.group | ConvertTo-HtmlSafe)</td>
  </tr>
</table>

<div class="signature">
  I certify that the above information is true and complete to the best of my knowledge.
  <br><br>
  Patient Signature: <span class="line">&nbsp;</span> &nbsp;&nbsp; Date: <span class="line" style="width:120pt">&nbsp;$signatureDate&nbsp;</span>
</div>

<div class="footer">
  Synthetic data for software demonstration only - not a real patient record.
</div>

</body>
</html>
"@
}

function Format-CheckListRows {
    param(
        [Parameter(Mandatory)][array]$Headers,
        $Items,
        [Parameter(Mandatory)][string[]]$ItemFieldOrder,
        [Parameter(Mandatory)][string]$EmptyMessage
    )

    if (-not $Items -or @($Items).Count -eq 0) {
        $colspan = $Headers.Count
        return "<tr><td colspan=`"$colspan`" class=`"empty`">$($EmptyMessage | ConvertTo-HtmlSafe)</td></tr>"
    }

    $rows = foreach ($item in $Items) {
        $cells = foreach ($field in $ItemFieldOrder) {
            "<td>$($item.$field | ConvertTo-HtmlSafe)</td>"
        }
        "<tr>$($cells -join '')</tr>"
    }
    return $rows -join "`n"
}

function Format-MedicalHistoryHtml {
    param(
        [Parameter(Mandatory)]$Form
    )

    $styles = Get-CommonStyles

    $conditionsRows = Format-CheckListRows `
        -Headers @("Condition", "Year of onset", "Status") `
        -Items $Form.conditions `
        -ItemFieldOrder @("name", "yearOfOnset", "status") `
        -EmptyMessage "None reported."

    $surgeriesRows = Format-CheckListRows `
        -Headers @("Procedure", "Year", "Facility") `
        -Items $Form.surgeries `
        -ItemFieldOrder @("procedure", "year", "facility") `
        -EmptyMessage "None reported."

    $medsRows = Format-CheckListRows `
        -Headers @("Medication", "Dose", "Frequency", "Indication") `
        -Items $Form.medications `
        -ItemFieldOrder @("name", "dose", "frequency", "indication") `
        -EmptyMessage "None reported."

    $allergyRows = Format-CheckListRows `
        -Headers @("Allergen", "Reaction", "Severity") `
        -Items $Form.allergies `
        -ItemFieldOrder @("allergen", "reaction", "severity") `
        -EmptyMessage "No known drug or food allergies."

    $familyRows = Format-CheckListRows `
        -Headers @("Relative", "Condition", "Age of onset") `
        -Items $Form.familyHistory `
        -ItemFieldOrder @("relative", "condition", "ageOfOnset") `
        -EmptyMessage "None reported."

    $signatureDate = (Get-Date).ToString("yyyy-MM-dd")

    return @"
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
$styles
</style>
</head>
<body>

<div class="letterhead">
  <h1>MEDICAL HISTORY QUESTIONNAIRE</h1>
  <div class="meta">Please complete every section. Write "None" or "N/A" if not applicable.</div>
</div>

<h2>Past and Active Medical Conditions</h2>
<table class="checklist">
  <thead><tr><th>Condition</th><th>Year of onset</th><th>Status</th></tr></thead>
  <tbody>
$conditionsRows
  </tbody>
</table>

<h2>Prior Surgeries and Hospitalizations</h2>
<table class="checklist">
  <thead><tr><th>Procedure</th><th>Year</th><th>Facility</th></tr></thead>
  <tbody>
$surgeriesRows
  </tbody>
</table>

<h2>Current Medications</h2>
<table class="checklist">
  <thead><tr><th>Medication</th><th>Dose</th><th>Frequency</th><th>Indication</th></tr></thead>
  <tbody>
$medsRows
  </tbody>
</table>

<h2>Allergies</h2>
<table class="checklist">
  <thead><tr><th>Allergen</th><th>Reaction</th><th>Severity</th></tr></thead>
  <tbody>
$allergyRows
  </tbody>
</table>

<h2>Family History</h2>
<table class="checklist">
  <thead><tr><th>Relative</th><th>Condition</th><th>Age of onset</th></tr></thead>
  <tbody>
$familyRows
  </tbody>
</table>

<h2>Social History</h2>
<table class="fields">
  <tr><td class="label">Smoking:</td><td class="value">$($Form.social.smoking | ConvertTo-HtmlSafe)</td></tr>
  <tr><td class="label">Alcohol:</td><td class="value">$($Form.social.alcohol | ConvertTo-HtmlSafe)</td></tr>
  <tr><td class="label">Recreational drugs:</td><td class="value">$($Form.social.drugs | ConvertTo-HtmlSafe)</td></tr>
</table>

<div class="signature">
  I certify that the above medical history is accurate to the best of my knowledge.
  <br><br>
  Patient Signature: <span class="line">&nbsp;</span> &nbsp;&nbsp; Date: <span class="line" style="width:120pt">&nbsp;$signatureDate&nbsp;</span>
</div>

<div class="footer">
  Synthetic data for software demonstration only - not a real patient record.
</div>

</body>
</html>
"@
}

function Format-ConsentHtml {
    param(
        [Parameter(Mandatory)]$Form
    )

    $styles = Get-CommonStyles

    $patientNameSafe = $Form.patientName | ConvertTo-HtmlSafe
    $signatureDateSafe = $Form.signatureDate | ConvertTo-HtmlSafe
    $clinicNameSafe = $Form.clinicName | ConvertTo-HtmlSafe
    $clinicAddressSafe = $Form.clinicAddress | ConvertTo-HtmlSafe

    return @"
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
$styles
</style>
</head>
<body>

<div class="letterhead">
  <h1>$clinicNameSafe</h1>
  <div class="meta">$clinicAddressSafe</div>
</div>

<h2 style="text-align:center; border:none;">HIPAA PRIVACY ACKNOWLEDGMENT &amp; CONSENT FOR TREATMENT</h2>

<div class="consent-body">
  <p>By signing this document, I, <strong>$patientNameSafe</strong>, acknowledge and agree to the following:</p>

  <ol>
    <li><strong>Notice of Privacy Practices.</strong> I have received, read, and understand this practice's Notice of Privacy Practices, which describes how my Protected Health Information (PHI) may be used and disclosed in accordance with the Health Insurance Portability and Accountability Act of 1996 (HIPAA).</li>

    <li><strong>Consent for Treatment.</strong> I voluntarily consent to medical treatment, examinations, diagnostic procedures, and other care provided by the clinicians, employees, and authorized agents of this practice that they deem necessary or advisable for my health and well-being.</li>

    <li><strong>Use and Disclosure of PHI.</strong> I authorize this practice to use and disclose my PHI for the purposes of treatment, payment, and health-care operations as described in the Notice of Privacy Practices, including communication with other treating providers, insurance carriers, and laboratories.</li>

    <li><strong>Financial Responsibility.</strong> I accept financial responsibility for services rendered, regardless of insurance coverage. I understand that estimates of insurance coverage are not a guarantee of payment by my insurer.</li>

    <li><strong>Right to Revoke.</strong> I understand that I may revoke this consent in writing at any time, except to the extent that the practice has already taken action in reliance on it.</li>
  </ol>

  <p>I have had the opportunity to ask questions about this consent and have had those questions answered to my satisfaction. I sign this consent voluntarily and of my own free will.</p>
</div>

<div class="signature">
  <table class="fields">
    <tr>
      <td class="label">Patient Name (printed):</td>
      <td class="value">$patientNameSafe</td>
    </tr>
    <tr>
      <td class="label">Patient Signature:</td>
      <td class="value"><span class="cursive">$patientNameSafe</span></td>
    </tr>
    <tr>
      <td class="label">Date:</td>
      <td class="value">$signatureDateSafe</td>
    </tr>
  </table>
</div>

<div class="footer">
  Synthetic data for software demonstration only - not a real patient record.
</div>

</body>
</html>
"@
}

function Format-IntakeHtml {
    param(
        [Parameter(Mandatory)][string]$FormTypeName,
        [Parameter(Mandatory)]$Form
    )

    switch ($FormTypeName) {
        'Demographics' { return Format-DemographicsHtml -Form $Form }
        'MedicalHistory' { return Format-MedicalHistoryHtml -Form $Form }
        'Consent' { return Format-ConsentHtml -Form $Form }
    }

    throw "Unknown form type: $FormTypeName"
}

# ----- mPDF render (mirrors generate-lab-pdf.ps1) ---------------------------

function Invoke-MpdfRender {
    param(
        [Parameter(Mandatory)][string]$Html,
        [Parameter(Mandatory)][string]$OutputPath
    )

    $stamp = [Guid]::NewGuid().ToString('N').Substring(0, 8)
    $htmlInContainer = "/tmp/intake-$stamp.html"
    $pdfInContainer = "/tmp/intake-$stamp.pdf"
    $phpInContainer = "/tmp/intake-render-$stamp.php"

    $hostHtml = Join-Path ([IO.Path]::GetTempPath()) "intake-$stamp.html"
    $hostPhp = Join-Path ([IO.Path]::GetTempPath()) "intake-render-$stamp.php"

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
    $reportsDir = Join-Path $PSScriptRoot "intake-forms"
    if (-not (Test-Path $reportsDir)) {
        New-Item -ItemType Directory -Path $reportsDir -Force | Out-Null
    }
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutFile = Join-Path $reportsDir "intake-$FormType-$($patient.Pid)-$timestamp.pdf"
}

Write-Host "Calling OpenAI ($Model) for synthetic $FormType intake form..."
$form = Invoke-OpenAIIntakeForm `
    -Initials $patient.Initials `
    -Age $patient.Age `
    -Sex $patient.Sex `
    -FormTypeName $FormType `
    -SeedHint $Seed `
    -ModelName $Model

Write-Host "Building HTML and rendering via mPDF inside the openemr container..."
$html = Format-IntakeHtml -FormTypeName $FormType -Form $form
Invoke-MpdfRender -Html $html -OutputPath $OutFile

Write-Host ""
Write-Host "Intake form generated:"
Write-Host "  File:     $OutFile"
Write-Host "  Type:     $FormType"
Write-Host "  Patient:  pid $($patient.Pid) ($($patient.Initials), age $($patient.Age), $($patient.Sex))"

switch ($FormType) {
    'Demographics' {
        Write-Host "  Subject:  $($form.firstName) $($form.lastName), $($form.address.city), $($form.address.state)"
    }
    'MedicalHistory' {
        $cnt = @($form.conditions).Count
        $med = @($form.medications).Count
        $alg = @($form.allergies).Count
        Write-Host "  Subject:  $cnt conditions, $med medications, $alg allergies"
    }
    'Consent' {
        Write-Host "  Subject:  $($form.patientName) signed $($form.signatureDate) at $($form.clinicName)"
    }
}
