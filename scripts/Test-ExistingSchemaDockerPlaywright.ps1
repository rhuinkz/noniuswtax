# NONIUSWTAX local Docker + Chromium Playwright schema compatibility test.
# Reads ONLY the previously generated zero-row structural JSON report.
# No live database connection, no production imports or historical record values.
param(
    [string]$SchemaReportPath = '.\schema-preflight-results\existing-schema-report.json'
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$schemaPath = (Resolve-Path -LiteralPath $SchemaReportPath).Path
if ([System.IO.Path]::GetExtension($schemaPath).ToLowerInvariant() -ne '.json') {
    throw 'Only the metadata-only existing-schema-report.json is permitted. NEVER use a SQL backup.'
}
if ((Get-Item -LiteralPath $schemaPath).Length -gt 10000000) {
    throw 'Unexpectedly large metadata file. Do not provide an actual database dump.'
}
$inputReport = Get-Content -LiteralPath $schemaPath -Raw | ConvertFrom-Json
if ($inputReport.report_type -ne 'SCHEMA_ONLY_NO_PRODUCTION_RECORDS' -or
    $inputReport.schema.format -ne 'NONIUS_SCHEMA_PREFLIGHT_V1' -or
    $inputReport.source_data_rows_inspected -ne $false -or
    $inputReport.source_data_sanitized -ne $false -or
    $inputReport.real_installed_upgrade_tested -ne $false -or
    $inputReport.production_approval -ne 'PENDING') {
    throw 'This is not the required schema-only privacy preflight report.'
}
$pathList = @($inputReport.schema.tables)
if ($pathList.Count -lt 6) { throw 'Schema inventory incomplete.' }
$outDir = Join-Path $root 'existing-compat-results'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
foreach ($name in @('results.json','existing-schema-comparison.json','compatibility-summary.json')) {
    $f = Join-Path $outDir $name
    if (Test-Path -LiteralPath $f) { Remove-Item -LiteralPath $f -Force }
}
$oldReportEnv = [Environment]::GetEnvironmentVariable('NONIUS_SCHEMA_REPORT_PATH')
$env:NONIUS_SCHEMA_REPORT_PATH = $schemaPath
$compose = @('compose','-p','nonius-existing-compatibility',
            '-f','compose.release-test.yml',
            '-f','compose.existing-schema-compare.yml')
function RunDocker([string[]]$argsList) {
    & docker @argsList
    if ($LASTEXITCODE -ne 0) { throw ('Docker failed: exit code ' + $LASTEXITCODE) }
}
$browserPassed = $false
$comparisonGenerated = $false
Write-Host 'NONIUSWTAX: LOCAL DOCKER + PLAYWRIGHT EXISTING SCHEMA COMPARISON' -ForegroundColor Cyan
Write-Host ('Input: structural JSON only (' + $pathList.Count + ' installed tables).')
Write-Host 'The web application is freshly installed with synthetic test records only.'
Write-Host 'No real SQL exports or live records enter this Docker test.'
try {
    RunDocker (@('info','--format','{{.ServerVersion}}'))
    RunDocker ($compose + @('down','--volumes','--remove-orphans'))
    RunDocker ($compose + @('build','web','browser'))
    RunDocker ($compose + @('up','-d','--wait','db','web'))
    Write-Host 'STAGE 1/2: Playwright fresh installer, roles, 2307 PDF, 10k synthetic records and integrity tests...' -ForegroundColor Cyan
    RunDocker ($compose + @('run','--rm','browser'))
    $browserPassed = $true
    Write-Host 'STAGE 2/2: Compare table, column, key, index and trigger metadata...' -ForegroundColor Cyan
    RunDocker ($compose + @('run','--rm','--no-deps','browser','node','/suite/compare-existing-schema.cjs'))
    $comparisonGenerated = $true
} catch {
    Write-Host ('TEST ERROR: '+$_.Exception.Message) -ForegroundColor Red
} finally {
    Write-Host 'Removing ONLY the disposable nonius-existing-compatibility Docker containers and volume...' -ForegroundColor Yellow
    $cleanup = $compose + @('down','--volumes','--remove-orphans')
    & docker @cleanup
    if ($null -eq $oldReportEnv) {
        Remove-Item Env:\NONIUS_SCHEMA_REPORT_PATH -ErrorAction SilentlyContinue
    } else { $env:NONIUS_SCHEMA_REPORT_PATH = $oldReportEnv }
}
$playwright = [ordered]@{ passed=0; failed=0; skipped=0 }
$rawFile = Join-Path $outDir 'results.json'
if (Test-Path -LiteralPath $rawFile) {
    $stats = (Get-Content -LiteralPath $rawFile -Raw | ConvertFrom-Json).stats
    $playwright.passed = [int]$stats.expected
    $playwright.failed = [int]$stats.unexpected
    $playwright.skipped = [int]$stats.skipped
}
$drift = $null
$comparisonFile = Join-Path $outDir 'existing-schema-comparison.json'
if ($comparisonGenerated -and (Test-Path -LiteralPath $comparisonFile)) {
    $drift = Get-Content -LiteralPath $comparisonFile -Raw | ConvertFrom-Json
}
$browserValid = $browserPassed -and $playwright.passed -ge 12 -and
    $playwright.failed -eq 0 -and $playwright.skipped -eq 0
$status = if (!$browserValid -or !$comparisonGenerated -or $null -eq $drift) {
    'AUTOMATED_TEST_FAILURE'
} elseif ($drift.status -eq 'STRUCTURAL_MATCH') {
    'PLAYWRIGHT_PASS_AND_SCHEMA_MATCH'
} else {
    'PLAYWRIGHT_PASS_SCHEMA_DRIFT_REVIEW_REQUIRED'
}
$summary = [ordered]@{
    generated_utc = (Get-Date).ToUniversalTime().ToString('o')
    technical_result = $status
    playwright = $playwright
    installed_schema_table_count = $pathList.Count
    installed_schema_trigger_count = @($inputReport.schema.triggers).Count
    source_commit_under_test = 'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
    source_schema_structural_result = if ($null -ne $drift) { $drift.status } else { 'NO_RESULT' }
    missing_schema_items = if ($null -ne $drift) { $drift.missing_structure_items } else { $null }
    extra_schema_items = if ($null -ne $drift) { $drift.extra_structure_items } else { $null }
    changed_column_definitions = if ($null -ne $drift) { $drift.changed_columns } else { $null }
    actual_existing_transactions_imported = $false
    historical_data_sanitized = $false
    actual_installed_upgrade_tested = $false
    production_approval = 'PENDING'
    note = 'The installed database was inspected as structure-only metadata. All Playwright records are fictional; schema drift requires manual migration review. No BIR/legal approval inferred.'
}
$utf8 = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Join-Path $outDir 'compatibility-summary.json'),
    (($summary | ConvertTo-Json -Depth 10) + [Environment]::NewLine),$utf8)
Write-Host ('Summary: ' + (Join-Path $outDir 'compatibility-summary.json'))
Write-Host ('Structural comparison: ' + $comparisonFile)
Write-Host ('Result: ' + $status)
if ($status -eq 'PLAYWRIGHT_PASS_AND_SCHEMA_MATCH') { exit 0 }
if ($status -eq 'PLAYWRIGHT_PASS_SCHEMA_DRIFT_REVIEW_REQUIRED') { exit 2 }
exit 1
