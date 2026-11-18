# NONIUSWTAX: One closed-box rehearsal on an authorized PRIVATE full SQL backup.
# NO live Hostinger connection, Apache, exposed port, or uploaded database.
param(
    [Parameter(Mandatory=$true)][string]$PrivateSqlPath,
    [switch]$ApprovedForLocalPrivateData
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
if(!$ApprovedForLocalPrivateData){
    throw 'STOP: Obtain data-owner authorization for local processing, then explicitly set -ApprovedForLocalPrivateData. This input is NOT anonymized.'
}
$src=(Resolve-Path -LiteralPath $PrivateSqlPath).Path
if(!(Test-Path -LiteralPath $src -PathType Leaf) -or [IO.Path]::GetExtension($src).ToLowerInvariant() -ne '.sql'){
    throw 'Provide a local plain full SQL export, not a URL, ZIP, or remote connection.'
}
$prefix=[IO.Path]::GetFullPath($root).TrimEnd('\','/')+[IO.Path]::DirectorySeparatorChar
if($src.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){
    throw 'Raw historical data must remain OUTSIDE the Git repository.'
}
$size=(Get-Item -LiteralPath $src).Length
if($size -lt 500 -or $size -gt 4294967296){
    throw 'Unexpected SQL size. 4 GiB maximum; obtain an appropriate private export.'
}
$sawTable=$false;$sawInsert=$false;$lineNo=0
foreach($line in [IO.File]::ReadLines($src)){
    $lineNo++
    if($line -match '(?i)^\s*CREATE\s+TABLE\b'){$sawTable=$true}
    if($line -match '(?i)^\s*INSERT\s+INTO\b'){$sawInsert=$true}
    if($line -match '(?i)^\s*(?:CREATE|DROP|ALTER)\s+DATABASE\b|^\s*USE\s+|^\s*(?:CREATE\s+USER|GRANT|REVOKE|FLUSH)\b|^\s*SOURCE\s+|^\s*LOAD\s+DATA\b|^\s*SET\s+GLOBAL\b|^\s*\\\.|INTO\s+OUTFILE\b|^\s*CREATE\s+EVENT\b'){
        throw ('Unsupported SQL operation near line '+$lineNo+'. Export one database without CREATE DATABASE/USE or account operations.')
    }
}
if(!$sawTable -or !$sawInsert){
    throw 'A full Structure+Data backup is required. Structure-only cannot rehearse historical data migration.'
}
$reportDir=Join-Path $root 'private-historical-results'
New-Item -ItemType Directory -Force -Path $reportDir | Out-Null
$reportFile=Join-Path $reportDir 'private-historical-summary.json'
if(Test-Path -LiteralPath $reportFile){Remove-Item -LiteralPath $reportFile -Force}
$sourceHash=(Get-FileHash -LiteralPath $src -Algorithm SHA256).Hash.ToLowerInvariant()
$env:NONIUS_PRIVATE_SQL_PATH=$src
$dc=@('compose','-p','nonius-private-historical-drill','-f','compose.private-historical-drill.yml')
function RunQuietDocker([string]$name,[string[]]$argList){
    Write-Host ('STAGING: '+$name) -ForegroundColor Cyan
    # Suppress all output: SQL engine errors may quote private source text.
    & docker @argList 2>$null | Out-Null
    if($LASTEXITCODE -ne 0){
        throw ($name+' failed. No sensitive SQL details were printed.')
    }
}
$complete=$false
Write-Host 'NONIUSWTAX — OFFLINE PRIVATE HISTORICAL MIGRATION AND RESTORE' -ForegroundColor Cyan
Write-Host 'No host ports, no Apache, no database connection outside Docker.'
try{
    RunQuietDocker 'Verify local Docker engine' @('info','--format','{{.ServerVersion}}')
    RunQuietDocker 'Remove this test project previous disposable volumes' ($dc+@('down','--volumes','--remove-orphans'))
    RunQuietDocker 'Build PHP CLI image without private backup' ($dc+@('build','cli'))
    RunQuietDocker 'Start isolated temporary MariaDB' ($dc+@('up','-d','--wait','db'))
    $importCmd='sed -E -f /private/normalize-trigger-definers.sed /private/input.sql | MYSQL_PWD="$DB_PASS" mariadb --host="$DB_HOST" --user="$DB_USER" --database="$DB_NAME"'
    RunQuietDocker 'Import full SQL only into disposable internal DB' ($dc+@('run','--rm','--no-deps','--entrypoint','sh','importer','-ec',$importCmd))
    $cliScript='/var/www/html/tests/release/private-historical-check.php'
    RunQuietDocker 'Fingerprint all historical rows privately and confirm ONLY migration 037 pending' ($dc+@('run','--rm','--no-deps','cli',$cliScript,'baseline'))
    $dumpCmd='MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump --user=root --single-transaction --routines --events --triggers "$MARIADB_DATABASE" > /private-backup/pre-upgrade.sql && test -s /private-backup/pre-upgrade.sql'
    RunQuietDocker 'Make full pre-migration restore image inside private Docker volume' ($dc+@('exec','-T','db','sh','-ec',$dumpCmd))
    RunQuietDocker 'Apply migration 037 via PHP CLI bootstrap to disposable clone' ($dc+@('run','--rm','--no-deps','cli',$cliScript,'migrate'))
    RunQuietDocker 'Verify trigger 15, checked hashes and unchanged business records' ($dc+@('run','--rm','--no-deps','cli',$cliScript,'after'))
    $restoreCmd='MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --user=root -e "CREATE DATABASE nonius_historical_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT SELECT ON nonius_historical_restored.* TO ''nonius_historical_local''@''%'';" && MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --user=root nonius_historical_restored < /private-backup/pre-upgrade.sql'
    RunQuietDocker 'Restore separate second original DB (no production impact)' ($dc+@('exec','-T','db','sh','-ec',$restoreCmd))
    Write-Host 'STAGING: Compare all restored original-table digests privately...' -ForegroundColor Cyan
    $argsList=$dc+@('run','--rm','--no-deps','-e','DB_NAME=nonius_historical_restored','cli',$cliScript,'restored')
    $out=(& docker @argsList 2>$null | Out-String).Trim()
    if($LASTEXITCODE -ne 0){throw 'Private DB restore check failed. No private row values disclosed.'}
    $result=$out | ConvertFrom-Json
    if($result.status -ne 'PASS' -or $result.original_triggers -ne 14 -or
        $result.trigger_count_after_clone_upgrade -ne 15 -or
        $result.migration037_initially -ne 'NO_ROW' -or
        $result.restored_original_schema_and_data_digest_match -ne $true -or
        $result.all_business_table_content_digests_unchanged -ne $true -or
        $result.original_database_values_exported_in_report -ne $false -or
        $result.production_approval -ne 'PENDING'){
        throw 'Offline restore report did not satisfy all required safety checks.'
    }
    $result|Add-Member -NotePropertyName 'generated_at_utc' -NotePropertyValue ((Get-Date).ToUniversalTime().ToString('o'))
    $result|Add-Member -NotePropertyName 'private_input_sha256' -NotePropertyValue $sourceHash
    $result|Add-Member -NotePropertyName 'tested_source_commit' -NotePropertyValue 'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
    $result|Add-Member -NotePropertyName 'production_go' -NotePropertyValue $false
    [IO.File]::WriteAllText($reportFile,(($result|ConvertTo-Json -Depth 12)+[Environment]::NewLine),(New-Object System.Text.UTF8Encoding($false)))
    $complete=$true
}catch{
    Write-Host ('NO-GO: '+$_.Exception.Message) -ForegroundColor Red
}finally{
    Write-Host 'Destroying ONLY the disposable nonius-private-historical-drill DB, backup and evidence volumes...' -ForegroundColor Yellow
    & docker @($dc+@('down','--volumes','--remove-orphans')) 2>$null | Out-Null
    Remove-Item Env:\NONIUS_PRIVATE_SQL_PATH -ErrorAction SilentlyContinue
}
if($complete){
    Write-Host 'PASS: private offline historical migration 037 and complete original DB restore verified.' -ForegroundColor Green
    Write-Host ('Aggregate-only result: '+$reportFile)
    Write-Host 'PRODUCTION NO-GO: sanitization, web UAT, BIR, security, load and signoff are separate.' -ForegroundColor Yellow
    exit 0
}
Write-Host 'FAIL: no passing private-data report accepted. Never attempt live DDL to bypass this gate.' -ForegroundColor Red
exit 1
