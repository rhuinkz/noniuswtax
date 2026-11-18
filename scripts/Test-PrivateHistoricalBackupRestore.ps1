# NONIUSWTAX R1 private backup RESTORE verification.
# This tool NEVER connects to Hostinger, modifies live files or deploys anything.
# Restore the owner-provided FULL database SQL only inside a disposable,
# internal-network Docker MariaDB and inspect a private site archive without extracting.
# PRIVATE records remain solely on the operator's local encrypted disk/Docker.
param(
    [Parameter(Mandatory=$true)][string]$FullSqlPath,
    [Parameter(Mandatory=$true)][string]$WebsiteArchivePath,
    [string]$SchemaReportPath = '.\schema-preflight-results\existing-schema-report.json'
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$utf8=New-Object System.Text.UTF8Encoding($false)
$outputFolder=Join-Path $root 'readiness-results'
$output=Join-Path $outputFolder 'private-backup-restore-summary.json'
New-Item -ItemType Directory -Force -Path $outputFolder | Out-Null
if(Test-Path -LiteralPath $output){Remove-Item -LiteralPath $output -Force}
$started=(Get-Date).ToUniversalTime().ToString('o')
$report=[ordered]@{
    report_format='NONIUS_PRIVATE_BACKUP_RESTORE_V1'
    started_utc=$started
    completed_utc=$null
    test_status='NOT_EXECUTED'
    source_data_exported_by_this_script=$false
    source_data_copied_to_public_artifacts=$false
    mysql_data_restored_to_disposable_docker=$false
    private_database_contents_sanitized=$false
    historical_upgrade_or_rollback_tested=$false
    source_backup_point_in_time_consistency_independently_verified=$false
    second_independent_encrypted_copy_proven=$false
    final_r1_review_signed=$false
    production_release='NO_GO_PENDING_R1_TO_R8'
    database_backup_sha256=$null
    code_backup_sha256=$null
    code_archive_file_count=$null
    code_archive_version=$null
    code_archive_has_private_config=$false
    code_archive_has_storage=$false
    restored_table_count=$null
    restored_column_count=$null
    restored_trigger_count=$null
    restored_migration037_row_count=$null
    restored_transaction_count=$null
    restored_checked_count=$null
    restored_audit_count=$null
    restored_history_count=$null
    restored_migration_count=$null
    total_fk_orphans=$null
    errors=@()
}
$failure=$null
$dockerUsed=$false
$priorEnv=[Environment]::GetEnvironmentVariable('NONIUS_PRIVATE_SQL_PATH')
$priorFormat=[Environment]::GetEnvironmentVariable('NONIUS_PRIVATE_SQL_FORMAT')
$compose=@('compose','-p','nonius-private-recovery','-f','compose.private-backup-restore.yml')
function DoDocker([string[]]$items){
    & docker @items
    if($LASTEXITCODE -ne 0){throw ('Local Docker operation failed (exit '+$LASTEXITCODE+').')}
}
function SecurePath([string]$path,[string]$kind,[string[]]$extensions){
    $real=(Resolve-Path -LiteralPath $path).Path
    if(!(Test-Path -LiteralPath $real -PathType Leaf)){
        throw ('Missing private '+$kind+' file.')
    }
    $repoPrefix=[System.IO.Path]::GetFullPath($script:root).TrimEnd('\','/')+
        [System.IO.Path]::DirectorySeparatorChar
    if($real.StartsWith($repoPrefix,[System.StringComparison]::OrdinalIgnoreCase)){
        throw ('Place private '+$kind+' OUTSIDE the Git repository.')
    }
    $valid=$false
    foreach($suffix in $extensions){
        if($real.ToLowerInvariant().EndsWith($suffix)){$valid=$true}
    }
    if(!$valid){throw ('Unexpected private '+$kind+' format. Allowed: '+($extensions -join ', '))}
    return $real
}
function ValidateSQL([string]$sqlPath){
    $size=(Get-Item -LiteralPath $sqlPath).Length
    if($size -lt 100 -or $size -gt 10737418240){
        throw 'Database backup is empty or exceeds the 10 GiB guarded limit.'
    }
    $raw=[System.IO.File]::OpenRead($sqlPath)
    $payload=$raw
    try{
        if($sqlPath.ToLowerInvariant().EndsWith('.sql.gz')){
            $payload=[System.IO.Compression.GZipStream]::new(
                $raw,[System.IO.Compression.CompressionMode]::Decompress)
        }
        $reader=[System.IO.StreamReader]::new($payload)
        $seenTable=$false; $lineNumber=0
        try{
            while($null -ne ($line=$reader.ReadLine())){
                $lineNumber++
                if($line -match '(?i)^\s*CREATE\s+TABLE\b'){$seenTable=$true}
                if($line -match '(?i)^\s*(?:CREATE|DROP|ALTER)\s+DATABASE\b|^\s*USE\s+|^\s*(?:CREATE|ALTER|DROP)\s+USER\b|^\s*(?:GRANT|REVOKE|FLUSH|SHUTDOWN)\b|^\s*SOURCE\s+|^\s*\\\.|^\s*LOAD\s+DATA\b|^\s*SET\s+(?:GLOBAL|PERSIST)\b|\bINTO\s+(?:OUTFILE|DUMPFILE)\b|\bLOAD_FILE\s*\('){
                    throw ('Unsupported SQL statement near line '+$lineNumber+
                        '. Export Structure+Data+Triggers without database switching or account/global commands.')
                }
            }
        }finally{$reader.Dispose()}
        if(!$seenTable){throw 'No CREATE TABLE found. Export Structure AND Data, not Data-only.'}
    }finally{$payload.Dispose();$raw.Dispose()}
}
function InspectSiteArchive([string]$archivePath){
    $lower=$archivePath.ToLowerInvariant()
    $names=@(); $versionText=$null
    if($lower.EndsWith('.zip')){
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $z=[System.IO.Compression.ZipFile]::OpenRead($archivePath)
        try{
            $entries=@($z.Entries|Where-Object {$_.Name -ne ''})
            $names=@($entries|ForEach-Object {$_.FullName})
            $boots=@($entries|Where-Object {$_.FullName.Replace('\','/') -match '(^|/)lib/bootstrap\.php$'})
            if($boots.Count -ne 1){throw 'Code archive must contain exactly one lib/bootstrap.php.'}
            if($boots[0].Length -gt 1048576){throw 'Unexpected bootstrap size.'}
            $reader=[System.IO.StreamReader]::new($boots[0].Open())
            try{$versionText=$reader.ReadToEnd()}finally{$reader.Dispose()}
        }finally{$z.Dispose()}
    }elseif($lower.EndsWith('.tar.gz')){
        $tar=Get-Command tar -ErrorAction Stop
        $names=@(& $tar.Source -tzf $archivePath 2>$null)
        if($LASTEXITCODE -ne 0){throw 'Cannot read private .tar.gz file manifest.'}
        $boots=@($names|Where-Object {$_.Replace('\','/') -match '(^|/)lib/bootstrap\.php$'})
        if($boots.Count -ne 1){throw 'Site .tar.gz must contain exactly one lib/bootstrap.php.'}
        $versionText=((& $tar.Source -xOzf $archivePath $boots[0] 2>$null) -join [Environment]::NewLine)
        if($LASTEXITCODE -ne 0 -or $versionText.Length -gt 1048576){
            throw 'Cannot privately read code version from site archive.'
        }
    }else{throw 'Only .zip and .tar.gz website archives are allowed.'}
    $seen=[System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach($name in $names){
        $normal=([string]$name).Replace('\','/')
        if($normal -match '^(?:/|[A-Za-z]:)' -or
           $normal -match '(?:^|/)\.\.(?:/|$)' -or $normal.Contains([char]0)){
            throw 'Website archive has unsafe paths. No archive contents were unpacked.'
        }
        if(!$normal.EndsWith('/')){
            if(!$seen.Add($normal)){throw 'Website archive has duplicate normalized entry names.'}
        }
    }
    if($names.Count -lt 10){throw 'Website archive has too few entries for complete app recovery.'}
    if(@($names|Where-Object {$_.Replace('\','/') -match '(^|/)update\.php$'}).Count -ne 1 -or
       @($names|Where-Object {$_.Replace('\','/') -match '(^|/)lib/migrations\.php$'}).Count -ne 1){
        throw 'Website archive must contain update.php and lib/migrations.php exactly once.'
    }
    if($versionText -notmatch "const\s+APP_VERSION\s*=\s*'([^']+)'"){
        throw 'No installed APP_VERSION could be read from the website archive.'
    }
    $script:report.code_archive_version=$Matches[1]
    $script:report.code_archive_file_count=@($names|Where-Object {!$_.EndsWith('/')}).Count
    $script:report.code_archive_has_private_config=
        [bool](@($names|Where-Object {$_.Replace('\','/') -match '(^|/)config\.local\.php$'}).Count)
    $script:report.code_archive_has_storage=
        [bool](@($names|Where-Object {$_.Replace('\','/') -match '(^|/)storage/'}).Count)
}

Write-Host 'NONIUSWTAX PRIVATE BACKUP RESTORE — DISPOSABLE DOCKER ONLY' -ForegroundColor Cyan
Write-Host 'No Hostinger connections or public ports. Private SQL never leaves the operator computer.'
try{
    $sql=SecurePath $FullSqlPath 'database backup' @('.sql','.sql.gz')
    $siteArchive=SecurePath $WebsiteArchivePath 'website backup' @('.zip','.tar.gz')
    $expectedPath=(Resolve-Path -LiteralPath $SchemaReportPath).Path
    $expected=Get-Content -LiteralPath $expectedPath -Raw | ConvertFrom-Json
    if($expected.report_type -ne 'SCHEMA_ONLY_NO_PRODUCTION_RECORDS' -or
       $expected.schema.format -ne 'NONIUS_SCHEMA_PREFLIGHT_V1' -or
       @($expected.schema.tables).Count -ne 21 -or
       @($expected.schema.columns).Count -ne 177 -or
       @($expected.schema.triggers).Count -ne 14){
       throw 'Existing schema-only fingerprint does not match the previously verified 21/177/14 installed site.'
    }
    Write-Host 'Preflight: validating SQL export safety without displaying its records...'
    ValidateSQL $sql
    Write-Host 'Preflight: inspecting website archive entries without extracting contents...'
    InspectSiteArchive $siteArchive
    $report.database_backup_sha256=(Get-FileHash -LiteralPath $sql -Algorithm SHA256).Hash.ToLowerInvariant()
    $report.code_backup_sha256=(Get-FileHash -LiteralPath $siteArchive -Algorithm SHA256).Hash.ToLowerInvariant()
    $privateFolder=Join-Path (Split-Path -Parent $sql) 'nonius-private-restore-logs'
    New-Item -ItemType Directory -Path $privateFolder -Force | Out-Null
    $privateLog=Join-Path $privateFolder 'sql-import-private.log'
    $env:NONIUS_PRIVATE_SQL_PATH=$sql
    $env:NONIUS_PRIVATE_SQL_FORMAT=if($sql.ToLowerInvariant().EndsWith('.sql.gz')){'gzip'}else{'plain'}
    DoDocker (@('info','--format','{{.ServerVersion}}'))
    $dockerUsed=$true
    # The exact Compose project is separate from all prior synthetic test DBs.
    DoDocker ($compose + @('down','--volumes','--remove-orphans'))
    DoDocker ($compose + @('up','-d','--wait','db'))
    Write-Host 'Restoring the full historical SQL to an offline isolated MariaDB...'
    $cli='MYSQL_PWD="$DB_PASS" mariadb --batch --skip-column-names --host="$DB_HOST" --user="$DB_USER" "$DB_NAME"'
    $importCommand=if($env:NONIUS_PRIVATE_SQL_FORMAT -eq 'gzip'){'gzip -dc /private/input.backup | '+$cli}else{$cli+' < /private/input.backup'}
    $command=$compose+@('run','--rm','--no-deps','-T','--entrypoint','bash','inspector',
       '-o','pipefail','-ec',$importCommand)
    # Import errors may contain database literals. They go into a PRIVATE
    # local file OUTSIDE Git and are never printed or published by this script.
    & docker @command *> $privateLog
    if($LASTEXITCODE -ne 0){
        throw 'Private database import failed. Inspect private SQL log on your computer only. Do not upload it.'
    }
    $report.mysql_data_restored_to_disposable_docker=$true
    Write-Host 'Verifying restored tables, triggers, migration 037 and row totals (aggregates only)...'
    $queryArgs=$compose+@('run','--rm','--no-deps','-T','--entrypoint','sh',
      'inspector','-ec',($cli+' < /private/report.sql'))
    $queryOutput=@(& docker @queryArgs)
    if($LASTEXITCODE -ne 0){throw 'Database structural inspection failed; no sensitive rows will be logged.'}
    $jsonLines=@($queryOutput | Where-Object {$_ -match '^\s*\{'})
    if($jsonLines.Count -ne 1){throw 'Unexpected private restore metadata response.'}
    $restored=$jsonLines[0] | ConvertFrom-Json
    if($restored.format -ne 'NONIUS_PRIVATE_RESTORE_METADATA_V1'){
        throw 'Wrong database recovery report format.'
    }
    if([int]$restored.composite_foreign_keys -ne 0){throw 'Composite foreign keys need an independently approved checker.'}
    $fkArgs=$compose+@('run','--rm','--no-deps','-T','--entrypoint','sh',
       'inspector','-ec',($cli+' < /private/foreign-keys.sql'))
    $fkOutput=@(& docker @fkArgs)
    if($LASTEXITCODE -ne 0){throw 'Foreign-key consistency inspection failed.'}
    $numbers=@($fkOutput | Where-Object {$_ -match '^\s*\d+\s*$'})
    if($numbers.Count -ne 1){throw 'Unexpected foreign-key checker response.'}
    $orphans=[long]$numbers[0].Trim()
    $report.restored_table_count=[int]$restored.tables
    $report.restored_column_count=[int]$restored.columns
    $report.restored_trigger_count=[int]$restored.triggers
    $report.restored_migration037_row_count=[int]$restored.migration037_rows
    $report.restored_transaction_count=[long]$restored.transaction_rows
    $report.restored_checked_count=[long]$restored.checked_transaction_rows
    $report.restored_audit_count=[long]$restored.audit_rows
    $report.restored_history_count=[long]$restored.history_rows
    $report.restored_migration_count=[long]$restored.migration_rows
    $report.total_fk_orphans=$orphans
    if([int]$restored.tables -ne 21 -or [int]$restored.columns -ne 177 -or
       [int]$restored.triggers -ne 14 -or
       [int]$restored.migration037_rows -ne 0 -or
       [int]$restored.checked_hash_period_trigger -ne 0 -or $orphans -ne 0){
        throw 'Restored schema, migration 037, or FK integrity does not match the verified old installation.'
    }
    $report.test_status='LOCAL_RESTORE_VERIFIED_R1_SIGNOFF_PENDING'
    Write-Host 'PASS: restored all 21 tables, 177 columns and 14 original triggers.' -ForegroundColor Green
    Write-Host 'PASS: migration 037 still absent, FK orphans=0; aggregate history present.'
    Write-Host 'IMPORTANT: Independent backup provenance, point-in-time consistency, privacy and final recovery sign-off remain PENDING.'
}catch{
    $failure=$_.Exception.Message
    $report.test_status='RESTORE_TEST_FAILED'
    $report.errors=@($failure)
    Write-Host ('FAIL: '+$failure) -ForegroundColor Red
}finally{
    if($dockerUsed){
        Write-Host 'Destroying ONLY nonius-private-recovery containers and its temporary database volume...' -ForegroundColor Yellow
        $cleanup=$compose+@('down','--volumes','--remove-orphans')
        & docker @cleanup
        if($LASTEXITCODE -ne 0){
            $report.test_status='RESTORE_TEST_FAILED'
            $report.errors+=@('Disposable Docker recovery cleanup failed; manually inspect dedicated project and volume.')
        }
    }
    if($null -eq $priorEnv){Remove-Item Env:\NONIUS_PRIVATE_SQL_PATH -ErrorAction SilentlyContinue}
    else{$env:NONIUS_PRIVATE_SQL_PATH=$priorEnv}
    if($null -eq $priorFormat){Remove-Item Env:\NONIUS_PRIVATE_SQL_FORMAT -ErrorAction SilentlyContinue}
    else{$env:NONIUS_PRIVATE_SQL_FORMAT=$priorFormat}
    $report.completed_utc=(Get-Date).ToUniversalTime().ToString('o')
    [System.IO.File]::WriteAllText($output,(($report|ConvertTo-Json -Depth 10)+[Environment]::NewLine),$utf8)
    Write-Host ('Local metadata-only report: '+$output)
    Write-Host 'NEVER share the SQL, ZIP, import log, or deployed configuration. Keep on encrypted approved storage.'
}
if($report.test_status -eq 'LOCAL_RESTORE_VERIFIED_R1_SIGNOFF_PENDING'){exit 0}
exit 1
