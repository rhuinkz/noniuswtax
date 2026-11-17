# NONIUSWTAX: local-only inspection of an existing database STRUCTURE export.
# Never connects to Hostinger or production DB. No records are printed.
param(
    [Parameter(Mandatory = $true)][string]$SchemaSqlPath,
    [string]$ExistingCodeRoot = ''
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$src = (Resolve-Path -LiteralPath $SchemaSqlPath).Path
if (!(Test-Path -LiteralPath $src -PathType Leaf) -or
    [System.IO.Path]::GetExtension($src).ToLowerInvariant() -ne '.sql') {
    throw 'Use an existing plain .sql STRUCTURE ONLY export, not a database with records.'
}
$prefix = [System.IO.Path]::GetFullPath($root).TrimEnd('\','/') +
    [System.IO.Path]::DirectorySeparatorChar
if ($src.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Keep all database exports OUTSIDE the Git repository.'
}
$bytes = (Get-Item -LiteralPath $src).Length
if ($bytes -lt 100 -or $bytes -gt 16777216) {
    throw 'Schema-only SQL must be between 100 bytes and 16 MiB. Do not use a full backup with data.'
}
$delimiter = ';'
$sawTable = $false
$lineNo = 0
foreach ($line in [System.IO.File]::ReadLines($src)) {
    $lineNo++
    if ($line -match '^\s*DELIMITER\s+(\S+)') { $delimiter = $Matches[1]; continue }
    if ($line -match '(?i)^\s*CREATE\s+TABLE\b') { $sawTable = $true }
    if ($line -match '(?i)^\s*(?:CREATE|DROP)\s+DATABASE\b|^\s*USE\s+|^\s*(?:CREATE\s+USER|GRANT|REVOKE|FLUSH)\b|^\s*SOURCE\s+|^\s*LOAD\s+DATA\b|INTO\s+OUTFILE') {
        throw ('Unsupported schema SQL operation near line ' + $lineNo + '. Re-export Structure only with no CREATE DATABASE/USE.')
    }
    if ($delimiter -eq ';' -and $line -match '(?i)^\s*(?:INSERT|REPLACE|UPDATE|DELETE)\s+') {
        throw ('Possible data-bearing SQL near line ' + $lineNo + '. Export with Data disabled.')
    }
    if ($line -match '(?i)^\s*--\s*Dumping\s+data\s+for\s+table') {
        throw ('SQL includes a data section near line ' + $lineNo + '. Use Structure only.')
    }
}
if (!$sawTable) { throw 'No CREATE TABLE found. Export NONIUSWTAX table structure, not rows.' }

$version = $null
$bootHash = $null
if ($ExistingCodeRoot -ne '') {
    $code = (Resolve-Path -LiteralPath $ExistingCodeRoot).Path
    $boot = Join-Path $code 'lib\bootstrap.php'
    if (!(Test-Path -LiteralPath $boot -PathType Leaf)) {
        throw 'ExistingCodeRoot must point to local extracted website files with lib\bootstrap.php.'
    }
    $contents = [System.IO.File]::ReadAllText($boot)
    if ($contents -notmatch "const\s+APP_VERSION\s*=\s*'([^']+)'") {
        throw 'No installed application version was found in the local bootstrap file.'
    }
    $version = $Matches[1]
    $bootHash = (Get-FileHash -LiteralPath $boot -Algorithm SHA256).Hash.ToLowerInvariant()
}

$outDir = Join-Path $root 'schema-preflight-results'
$outFile = Join-Path $outDir 'existing-schema-report.json'
New-Item -ItemType Directory -Path $outDir -Force | Out-Null
if (Test-Path -LiteralPath $outFile) { Remove-Item -LiteralPath $outFile -Force }
$env:NONIUS_SCHEMA_SQL_PATH = $src
$compose = @('compose','-p','nonius-existing-schema-preflight',
             '-f','compose.existing-schema-preflight.yml')
$cli = 'MYSQL_PWD="$DB_PASS" mariadb --batch --skip-column-names --host="$DB_HOST" --user="$DB_USER" "$DB_NAME"'
function InvokeDocker([string[]]$argsList) {
    & docker @argsList
    if ($LASTEXITCODE -ne 0) { throw ('Docker command failed: exit code ' + $LASTEXITCODE) }
}
$completed = $false
Write-Host 'NONIUSWTAX — EXISTING SCHEMA-ONLY PREFLIGHT' -ForegroundColor Cyan
Write-Host 'No website, no production connections, no published database ports.'
Write-Host 'The input SQL is read-only and remains outside your Git repository.'
try {
    InvokeDocker (@('info','--format','{{.ServerVersion}}'))
    InvokeDocker ($compose + @('down','--volumes','--remove-orphans'))
    InvokeDocker ($compose + @('up','-d','--wait','db'))
    Write-Host 'Importing definitions into dedicated private temporary MariaDB...'
    InvokeDocker ($compose + @('run','--rm','--no-deps','-T','--entrypoint','sh',
        'inspector','-ec',($cli + ' < /inspection/input.sql')))
    Write-Host 'Collecting tables, columns, foreign keys, indexes and triggers only...'
    $cmd = $compose + @('run','--rm','--no-deps','-T','--entrypoint','sh',
        'inspector','-ec',($cli + ' < /inspection/report.sql'))
    $stdout = & docker @cmd
    if ($LASTEXITCODE -ne 0) { throw 'Schema report query failed.' }
    $jsonLines = @($stdout | Where-Object { $_ -match '^\s*\{' })
    if ($jsonLines.Count -ne 1) { throw 'Unexpected metadata output; no report was saved.' }
    $metadata = $jsonLines[0] | ConvertFrom-Json
    if ($metadata.format -ne 'NONIUS_SCHEMA_PREFLIGHT_V1' -or
        @($metadata.tables).Count -lt 6 -or @($metadata.columns).Count -lt 15) {
        throw 'This does not resemble a NONIUSWTAX application schema.'
    }
    $payload = [ordered]@{
        generated_utc = (Get-Date).ToUniversalTime().ToString('o')
        report_type = 'SCHEMA_ONLY_NO_PRODUCTION_RECORDS'
        source_export_sha256 = (Get-FileHash -LiteralPath $src -Algorithm SHA256).Hash.ToLowerInvariant()
        installed_code_version_from_optional_local_snapshot = $version
        installed_bootstrap_sha256 = $bootHash
        source_data_rows_inspected = $false
        source_data_sanitized = $false
        real_installed_upgrade_tested = $false
        production_approval = 'PENDING'
        schema = $metadata
        note = 'Metadata only; no row values. A Structure only export does not contain actual migration-history data.'
    }
    $json = $payload | ConvertTo-Json -Depth 20
    [System.IO.File]::WriteAllText($outFile, ($json + [Environment]::NewLine),
        (New-Object System.Text.UTF8Encoding($false)))
    Write-Host ('PASS: ' + @($metadata.tables).Count + ' tables, ' +
        @($metadata.columns).Count + ' columns, ' +
        @($metadata.triggers).Count + ' triggers.') -ForegroundColor Green
    Write-Host ('Safe structural report: ' + $outFile)
    Write-Host 'Do not send or publish the source SQL export, real supplier data, or config.local.php.'
    $completed = $true
} finally {
    Write-Host 'Deleting ONLY the isolated schema-preflight Docker project and its temporary database volume...' -ForegroundColor Yellow
    $cleanup = $compose + @('down','--volumes','--remove-orphans')
    & docker @cleanup
    Remove-Item Env:\NONIUS_SCHEMA_SQL_PATH -ErrorAction SilentlyContinue
}
if ($completed) { exit 0 }
exit 1
