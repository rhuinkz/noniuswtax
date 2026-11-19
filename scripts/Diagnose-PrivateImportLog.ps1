# NONIUSWTAX R1: inspect a LOCAL PRIVATE importer log WITHOUT outputting raw lines.
# Never connects to Hostinger, Docker, SQL server, or GitHub.
# The raw SQL import log may include confidential records: no raw text is
# printed, persisted in diagnostic JSON, or sent to any server.
param(
    [string]$LogPath = (Join-Path $env:USERPROFILE 'Documents\NONIUSWTAX_PRIVATE\nonius-private-restore-logs\sql-import-private.log'),
    [string]$RestoreSummaryPath = '.\readiness-results\private-backup-restore-summary.json',
    [string]$OutputPath = '.\readiness-results\private-import-diagnostic.json'
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$repoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $repoRoot
$report=[ordered]@{
    format='NONIUS_PRIVATE_IMPORT_ERROR_CLASSIFICATION_V1'
    generated_at_utc=(Get-Date).ToUniversalTime().ToString('o')
    source='PRIVATE_LOCAL_IMPORT_LOG_CLASSIFICATION_ONLY'
    diagnostic_status='NOT_ANALYZED'
    raw_log_read_locally_only=$true
    raw_log_contents_shared=$false
    real_database_import_retried=$false
    docker_started=$false
    hostinger_accessed=$false
    configuration_changed=$false
    reported_backup_sql_import_exit_code=$null
    log_size_bytes=$null
    mysql_error_codes=@()
    error_categories=@()
    signal_codes=@()
    mysql_error_line_count=0
    docker_progress_marker_count=0
    error_indication_line_count=0
    recommendation_code='NONE'
    has_unclassified_errors=$false
    production_release='NO_GO_PENDING_R1_TO_R8'
}
$codes=@{}
$signals=@{}
$known=@{
 '1044'='DATABASE_PERMISSION_DENIED';'1045'='DATABASE_LOGIN_DENIED'
 '1049'='TARGET_DATABASE_UNKNOWN';'1050'='TABLE_ALREADY_EXISTS'
 '1054'='UNKNOWN_COLUMN';'1061'='DUPLICATE_INDEX'
 '1062'='DUPLICATE_KEY';'1064'='SQL_SYNTAX_OR_DIALECT'
 '1067'='INVALID_DEFAULT';'1071'='INDEX_LENGTH'
 '1101'='INVALID_DEFAULT_DATA_TYPE';'1118'='ROW_SIZE_LIMIT'
 '1136'='INSERT_COLUMN_COUNT';'1142'='TABLE_PERMISSION_DENIED'
 '1146'='MISSING_TABLE';'1153'='PACKET_TOO_LARGE'
 '1193'='UNKNOWN_SERVER_VARIABLE';'1215'='FOREIGN_KEY_CREATION'
 '1217'='FOREIGN_KEY_RESTRICTION';'1227'='PRIVILEGE_OR_DEFINER_RESTRICTION'
 '1231'='SERVER_VARIABLE_VALUE';'1253'='CHARACTER_SET_COLLATION_MISMATCH'
 '1273'='UNKNOWN_COLLATION';'1293'='INVALID_TIMESTAMP_DEFAULT'
 '1419'='TRIGGER_CREATION_PRIVILEGE';'1449'='MISSING_ROUTINE_DEFINER'
 '1451'='FOREIGN_KEY_REFERENCED';'1452'='FOREIGN_KEY_ORPHAN'
 '2002'='SERVER_SOCKET_CONNECTION';'2003'='SERVER_TCP_CONNECTION'
 '2006'='SERVER_CONNECTION_DROPPED';'2013'='SERVER_CONNECTION_DROPPED'
 '3819'='CHECK_CONSTRAINT_FAILED'
}
function Add-Signal([string]$signal){
    if(!$signals.ContainsKey($signal)){$signals[$signal]=0}
    $signals[$signal]++
}
function Resolve-SafePath([string]$inputPath,[bool]$allowRepo){
    $resolved=(Resolve-Path -LiteralPath $inputPath).Path
    if(!(Test-Path -LiteralPath $resolved -PathType Leaf)){throw 'Expected a readable local file.'}
    if(!$allowRepo){
        $prefix=[System.IO.Path]::GetFullPath($repoRoot).TrimEnd('\','/')+
            [System.IO.Path]::DirectorySeparatorChar
        if($resolved.StartsWith($prefix,[System.StringComparison]::OrdinalIgnoreCase)){
            throw 'Private logs must remain OUTSIDE the Git repository.'
        }
    }
    return $resolved
}
try {
    $privateLog=Resolve-SafePath $LogPath $false
    $size=(Get-Item -LiteralPath $privateLog).Length
    if($size -gt 209715200){throw 'Private log too large for guarded read-only inspection (200 MiB).'}
    $report.log_size_bytes=$size
    if(Test-Path -LiteralPath $RestoreSummaryPath -PathType Leaf){
        $existing=Get-Content -LiteralPath $RestoreSummaryPath -Raw | ConvertFrom-Json
        if($existing.report_format -eq 'NONIUS_PRIVATE_BACKUP_RESTORE_V1'){
            $report.reported_backup_sql_import_exit_code=$existing.sql_import_docker_exit_code
        }
    }
    $logLines=0
    $reader=[System.IO.StreamReader]::new($privateLog,[System.Text.Encoding]::UTF8,$true)
    try{
        while($null -ne ($line=$reader.ReadLine())){
            $logLines++
            if($logLines -gt 1000000){throw 'Private log exceeded maximum guarded line count.'}
            # Never persist $line, text following the SQL error code, line
            # numbers, paths, SQL fragments, named DB users, TINs or row values.
            $matchesForLine=[regex]::Matches(
               $line,'(?i)\b(?:mariadb|mysql)?\s*(?:ERROR|ERROR\s*:)\s*([0-9]{3,5})\b')
            $hasErr=$false
            foreach($match in $matchesForLine){
                $numeric=[string]$match.Groups[1].Value
                $safeCode=([int]$numeric).ToString([System.Globalization.CultureInfo]::InvariantCulture)
                if(!$codes.ContainsKey($safeCode)){$codes[$safeCode]=0}
                $codes[$safeCode]++
                $report.mysql_error_line_count++
                $hasErr=$true
            }
            if($line -match '(?i)\bContainer\b.{0,80}\b(?:Creating|Created|Starting|Started|Running)\b'){
                $report.docker_progress_marker_count++
            }
            if($line -match '(?i)\b(?:gzip|zcat)\b.{0,100}\b(?:not in gzip format|invalid compressed|unexpected end|crc error|not found|truncated)\b'){
                Add-Signal 'COMPRESSED_BACKUP_STREAM_FAILURE'
                $hasErr=$true
            }
            if($line -match '(?i)\b(?:permission denied|access denied|super privilege|set user privilege)\b'){
                Add-Signal 'DATABASE_PRIVILEGE_SIGNAL'
                $hasErr=$true
            }
            if($line -match '(?i)\b(?:definer|create trigger)\b.{0,90}\b(?:not exist|not found|privilege|denied|fail)\b'){
                Add-Signal 'DEFINER_OR_TRIGGER_PRIVILEGE_SIGNAL'
                $hasErr=$true
            }
            if($line -match '(?i)\b(?:unknown collation|unsupported collation)\b'){
                Add-Signal 'COLLATION_COMPATIBILITY_SIGNAL'
                $hasErr=$true
            }
            if($line -match '(?i)\b(?:error response from daemon|oci runtime create failed|cannot connect to the docker daemon)\b'){
                Add-Signal 'DOCKER_INFRASTRUCTURE_SIGNAL'
                $hasErr=$true
            }
            if($line -match '(?i)\b(?:server has gone away|lost connection to (?:mysql|mariadb) server)\b'){
                Add-Signal 'DATABASE_CONNECTION_DROPPED_SIGNAL'
                $hasErr=$true
            }
            if($line -match '(?i)\b(?:no space left on device|disk full)\b'){
                Add-Signal 'LOCAL_STORAGE_EXHAUSTED_SIGNAL'
                $hasErr=$true
            }
            if($hasErr){$report.error_indication_line_count++}
        }
    }finally{
        $reader.Dispose()
    }
    $rows=New-Object 'System.Collections.Generic.List[object]'
    foreach($code in @($codes.Keys|Sort-Object {[int]$_})){
        $label=if($known.ContainsKey($code)){$known[$code]}else{'UNCLASSIFIED_MYSQL_ERROR'}
        $rows.Add([pscustomobject]@{code=$code;category=$label;count=$codes[$code]})|Out-Null
    }
    $report.mysql_error_codes=$rows.ToArray()
    $report.error_categories=@($rows.ToArray()|ForEach-Object {$_.category}|Sort-Object -Unique)
    $report.signal_codes=@($signals.Keys|Sort-Object)
    $report.has_unclassified_errors=([bool](@($rows.ToArray()|Where-Object {$_.category -eq 'UNCLASSIFIED_MYSQL_ERROR'}).Count))
    if($report.mysql_error_line_count -gt 0){
        $report.diagnostic_status='CLASSIFIED_MYSQL_ERROR_CODES'
    }elseif($signals.Count -gt 0){
        $report.diagnostic_status='CLASSIFIED_ERROR_SIGNALS_ONLY'
    }else{
        $report.diagnostic_status='NO_RECOGNIZED_ERROR_IN_PRIVATE_LOG'
    }
    if(@($report.error_categories|Where-Object {$_ -match 'DEFINER|PRIVILEGE|TRIGGER'}).Count -gt 0 -or
       @($report.signal_codes|Where-Object {$_ -match 'PRIVILEGE|DEFINER'}).Count -gt 0){
       $report.recommendation_code='DBA_REVIEW_TRIGGER_OR_DEFINER_PRIVILEGES_IN_ISOLATED_RESTORE'
    }elseif(@($report.error_categories|Where-Object {$_ -match 'COLLATION|SQL_SYNTAX|DIALECT'}).Count -gt 0 -or
       @($report.signal_codes|Where-Object {$_ -match 'COLLATION'}).Count -gt 0){
       $report.recommendation_code='DBA_REVIEW_SQL_DIALECT_OR_COLLATION_COMPATIBILITY'
    }elseif(@($report.signal_codes|Where-Object {$_ -match 'DOCKER|COMPRESSED|STORAGE'}).Count -gt 0){
       $report.recommendation_code='REVIEW_LOCAL_DOCKER_OR_COMPRESSED_FILE_HEALTH'
    }elseif($report.mysql_error_line_count -gt 0){
       $report.recommendation_code='REVIEW_MYSQL_ERROR_CLASS_WITHOUT_EXPOSING_RAW_LOG'
    }else{
       $report.recommendation_code='CHECK_IMPORT_EXIT_CODE_AND_PRIVATE_LOG_LOCALLY'
    }
    $outDir=Split-Path -Parent $OutputPath
    if(!(Test-Path -LiteralPath $outDir -PathType Container)){
        New-Item -ItemType Directory -Force -Path $outDir | Out-Null
    }
    $resolvedOutput=[System.IO.Path]::GetFullPath($OutputPath)
    [System.IO.File]::WriteAllText(
       $resolvedOutput,
       (($report|ConvertTo-Json -Depth 10)+[Environment]::NewLine),
       [System.Text.UTF8Encoding]::new($false)
    )
    Write-Host ('Private import log assessed locally. Classification: '+$report.diagnostic_status)
    Write-Host ('Numerical MariaDB error classes: '+(@($rows.ToArray()|ForEach-Object {$_.code}) -join ', '))
    Write-Host ('Only this aggregate-only diagnostic may be reviewed and shared: '+$resolvedOutput)
    Write-Host 'Never share the raw SQL import log, its lines, transaction values or full backup.'
    exit 0
}catch{
    # An error may involve a path or message carrying private information.
    # Do not print the exception or a stack trace to the console or CI.
    Write-Host 'Private import log could not be classified. Check the supplied local log path and access permissions privately.' -ForegroundColor Red
    exit 1
}
