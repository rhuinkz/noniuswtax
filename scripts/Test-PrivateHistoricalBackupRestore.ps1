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
    code_archive_safe_root_markers_ignored=0
    code_archive_duplicate_file_entries=0
    code_archive_duplicate_exact_path_entries=0
    code_archive_duplicate_alias_entries=0
    code_archive_duplicate_selected_site_entries=0
    code_archive_duplicate_unrelated_entries=0
    code_archive_duplicate_scope='NOT_ASSESSED'
    code_archive_app_root_candidates=$null
    code_archive_nonius_signature_candidates=0
    code_archive_target_db_candidates=0
    code_archive_target_url_candidates=0
    code_archive_verified_identity_candidates=0
    code_archive_identity_method='NONE'
    code_archive_expected_domain_candidates=$null
    code_archive_inner_archives_count=$null
    code_archive_site_selection='NOT_INSPECTED'
    code_archive_selected_root_file_count=$null
    database_and_site_backup_timestamps_match=$null
    backup_pair_timestamp_status='NOT_ASSESSED'
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
    # Hostinger account backups may contain multiple sites or nested packages.
    # Identify one exact application root; never print private archive paths.
    $isZip=$archivePath.ToLowerInvariant().EndsWith('.zip')
    $z=$null; $entries=@(); $names=@()
    if($isZip){
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $z=[System.IO.Compression.ZipFile]::OpenRead($archivePath)
        $entries=@($z.Entries | Where-Object {$_.Name -ne ''})
        $names=@($entries | ForEach-Object {$_.FullName})
    }elseif($archivePath.ToLowerInvariant().EndsWith('.tar.gz')){
        $tar=Get-Command tar -ErrorAction Stop
        $names=@(& $tar.Source -tzf $archivePath 2>$null)
        if($LASTEXITCODE -ne 0){throw 'Could not read TAR.GZ site inventory.'}
    }else{throw 'Only .zip and .tar.gz website archives are supported.'}
    try{
        $lookup=@{}
        $seen=[System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
        $safeRootDirectoryMarkersIgnored=0
        $duplicates=New-Object 'System.Collections.Generic.List[string]'
        $duplicateExact=0
        $duplicateAlias=0
        foreach($name in $names){
            $rawEntry=([string]$name).Replace('\','/')
            # tar often lists a harmless root directory marker "." or "./".
            # Ignore ONLY those markers, not parent traversal or absolute paths.
            if($rawEntry -eq '.' -or $rawEntry -match '^(?:\./)+$'){
                $safeRootDirectoryMarkersIgnored++
                continue
            }
            if(!$rawEntry -or $rawEntry -match '^(?:/|[A-Za-z]:)' -or
               $rawEntry -match '(^|/)\.\.(?:/|$)' -or $rawEntry.Contains([char]0)){
                throw 'Unsafe website archive path found (absolute, parent traversal or invalid). No archive data extracted.'
            }
            $normalized=$rawEntry
            while($normalized.StartsWith('./')){$normalized=$normalized.Substring(2)}
            if(!$normalized -or $normalized -match '^(?:/|[A-Za-z]:)' -or
               $normalized -match '(^|/)\.\.(?:/|$)' -or
               $normalized -match '(^|/)\.(?:/|$)' -or $normalized.Contains([char]0)){
                throw 'Unsafe website archive path found (ambiguous or invalid). No archive data extracted.'
            }
            if(!$normalized.EndsWith('/')){
                if(!$seen.Add($normalized)){
                    # Do not silently choose the first or last TAR version.
                    # Collect only aggregate counts; inspect the selected
                    # NONIUSWTAX site scope below before allowing any import.
                    $duplicates.Add($normalized) | Out-Null
                    if($lookup[$normalized] -ceq [string]$name){
                        $duplicateExact++
                    }else{
                        $duplicateAlias++
                    }
                    continue
                }
                $lookup[$normalized]=[string]$name
            }
        }
        $script:report.code_archive_safe_root_markers_ignored=$safeRootDirectoryMarkersIgnored
        $script:report.code_archive_duplicate_file_entries=$duplicates.Count
        $script:report.code_archive_duplicate_exact_path_entries=$duplicateExact
        $script:report.code_archive_duplicate_alias_entries=$duplicateAlias
        $script:report.code_archive_file_count=$lookup.Count
        $script:report.code_archive_inner_archives_count=@($lookup.Keys | Where-Object {
            $_ -match '(?i)\.(zip|tar|tar\.gz|tgz)$'
        }).Count
        $roots=@($lookup.Keys | Where-Object {$_ -match '(?i)(^|/)lib/bootstrap\.php$'})
        $script:report.code_archive_app_root_candidates=$roots.Count
        $expectedDomain='noniuswtax.depedtagb.online'
        $domainRegex='(?i)(^|/)'+[regex]::Escape($expectedDomain)+'/'
        $matched=@($roots | Where-Object {$_ -match $domainRegex})
        $script:report.code_archive_expected_domain_candidates=$matched.Count
        # Inspect only public NONIUSWTAX bootstrap identity and two literal
        # application config markers. In-memory only. Never evaluate PHP,
        # show archive paths, echo config, or place secrets into the report.
        # An unscoped multi-site archive is selected ONLY when the installed
        # app signature, expected database and base URL all match uniquely.
        function ReadPrivateSiteEntry([string]$normalizedPath){
            if(!$lookup.ContainsKey($normalizedPath)){return $null}
            if(@($duplicates | Where-Object {$_ -ceq $normalizedPath}).Count -gt 0){return $null}
            $content=$null
            if($isZip){
                $one=@($entries | Where-Object {$_.FullName -ceq $lookup[$normalizedPath]})
                if($one.Count -ne 1 -or $one[0].Length -gt 1048576){return $null}
                $reader=[System.IO.StreamReader]::new($one[0].Open())
                try{$content=$reader.ReadToEnd()}finally{$reader.Dispose()}
            }else{
                $content=((& $tar.Source -xOzf $archivePath $lookup[$normalizedPath] 2>$null) -join [Environment]::NewLine)
                if($LASTEXITCODE -ne 0){return $null}
            }
            if($null -eq $content -or $content.Length -gt 1048576){return $null}
            return $content
        }
        $signedRoots=New-Object 'System.Collections.Generic.List[string]'
        $identityMatches=New-Object 'System.Collections.Generic.List[string]'
        foreach($candidateRoot in $roots){
            $candidatePrefix=$candidateRoot.Substring(0,$candidateRoot.Length-'lib/bootstrap.php'.Length)
            $bootstrap=ReadPrivateSiteEntry $candidateRoot
            if($null -eq $bootstrap){continue}
            $hasCodeSignature=($bootstrap -cmatch "const\s+APP_NAME\s*=\s*'Non-IUS Withholding Tax Monitoring'\s*;") -and
                              ($bootstrap -match "const\s+APP_VERSION\s*=\s*'[0-9]+\.[0-9]+\.[0-9]+'")
            if(!$hasCodeSignature){continue}
            $script:report.code_archive_nonius_signature_candidates++
            $signedRoots.Add($candidateRoot) | Out-Null
            $configText=ReadPrivateSiteEntry ($candidatePrefix+'config.local.php')
            if($null -eq $configText){continue}
            $hasArrayDb=($configText -match "(?i)'db'\s*=>\s*array\s*\(")
            $targetDb=$hasArrayDb -and ($configText -match "(?i)'name'\s*=>\s*'u574137232_noniuswtax'")
            $targetUrl=($configText -match "(?i)'base_url'\s*=>\s*'https?://noniuswtax\.depedtagb\.online(?:/|')")
            if($targetDb){$script:report.code_archive_target_db_candidates++}
            if($targetUrl){$script:report.code_archive_target_url_candidates++}
            if($targetDb -and $targetUrl){
                $script:report.code_archive_verified_identity_candidates++
                $identityMatches.Add($candidateRoot) | Out-Null
            }
            $configText=$null; $bootstrap=$null
        }
        $selected=$null
        if($matched.Count -eq 1){
            $selected=$matched[0]
            if(!$signedRoots.Contains($selected)){
                $script:report.code_archive_site_selection='DOMAIN_WITHOUT_APP_SIGNATURE'
                throw 'Expected website domain path does not contain the NONIUSWTAX application signature.'
            }
            $script:report.code_archive_site_selection='EXPECTED_DOMAIN'
            $script:report.code_archive_identity_method='DOMAIN_PATH_PLUS_APP_SIGNATURE'
        }elseif($matched.Count -gt 1){
            $script:report.code_archive_site_selection='AMBIGUOUS_EXPECTED_DOMAIN'
            throw 'Multiple application copies under expected domain. Provide a site-specific website backup.'
        }elseif($roots.Count -eq 1){
            $selected=$roots[0]
            if(!$signedRoots.Contains($selected)){
                $script:report.code_archive_site_selection='UNSCOPED_WITHOUT_APP_SIGNATURE'
                throw 'Only archive application root does not contain the NONIUSWTAX application signature.'
            }
            if($selected -match '(?i)(^|/)(?:domains|sites)/[^/]+/' -or
               $selected -match '(?i)(^|/)[a-z0-9-]+(?:\.[a-z0-9-]+)+/'){
                $script:report.code_archive_site_selection='FOREIGN_OR_UNVERIFIED_DOMAIN'
                throw 'Single app is scoped to another domain; cannot accept it as NONIUSWTAX.'
            }
            $script:report.code_archive_site_selection='SINGLE_UNSCOPED_APP'
            $script:report.code_archive_identity_method='SINGLE_ROOT_PLUS_APP_SIGNATURE'
        }elseif($identityMatches.Count -eq 1){
            $selected=$identityMatches[0]
            $script:report.code_archive_site_selection='MATCHED_INSTALLED_DB_AND_URL'
            $script:report.code_archive_identity_method='APP_SIGNATURE_DB_AND_BASE_URL'
        }else{
            $script:report.code_archive_site_selection=if($roots.Count -eq 0){'NO_APPLICATION_ROOT'}elseif($identityMatches.Count -gt 1){'AMBIGUOUS_INSTALLED_IDENTITY'}else{'NO_VERIFIED_INSTALLED_IDENTITY'}
            throw ('Cannot uniquely identify installed NONIUSWTAX website. '+
                $roots.Count+' app roots, '+
                $script:report.code_archive_nonius_signature_candidates+' NONIUSWTAX code signatures, '+
                $script:report.code_archive_target_db_candidates+' expected DB bindings, '+
                $script:report.code_archive_target_url_candidates+' expected site URL bindings, '+
                $script:report.code_archive_verified_identity_candidates+' full matches. '+
                'No files were extracted. Obtain an unambiguous site-specific website backup if none matches.')
        }
        # Duplicate paths outside the selected site may be unrelated
        # Hostinger account content and are never unpacked or executed.
        # Any duplicate INSIDE the selected site is ambiguous, including
        # duplicate bootstrap, updater, migration or configuration files.
        $prefix=$selected.Substring(0,$selected.Length-'lib/bootstrap.php'.Length)
        $dupsInSite=0
        $dupsOutside=0
        foreach($dup in $duplicates){
            if($dup.StartsWith($prefix,[System.StringComparison]::OrdinalIgnoreCase)){
                $dupsInSite++
            }else{$dupsOutside++}
        }
        $script:report.code_archive_duplicate_selected_site_entries=$dupsInSite
        $script:report.code_archive_duplicate_unrelated_entries=$dupsOutside
        $script:report.code_archive_duplicate_scope=if($dupsInSite -gt 0){
            'SELECTED_SITE_AMBIGUOUS'
        }elseif($dupsOutside -gt 0){
            'ONLY_OUTSIDE_SELECTED_SITE_REVIEW'
        }else{'NONE'}
        if($dupsInSite -gt 0){
            $script:report.code_archive_site_selection='AMBIGUOUS_DUPLICATE_SITE_FILES'
            throw ('Selected NONIUSWTAX application contains '+$dupsInSite+
                ' repeated file path(s). Do NOT choose a TAR version automatically. '+
                'Get a clean site-specific archive; no database restore was started.')
        }
        if($dupsOutside -gt 0){
            Write-Host ('NOTICE: '+$dupsOutside+
                ' duplicate TAR file entry(s) are outside the selected app. '+
                'Their provenance still needs independent backup review.') -ForegroundColor Yellow
        }
        foreach($required in @('update.php','lib/migrations.php','login.php')){
            if(!$lookup.ContainsKey($prefix+$required)){
                $script:report.code_archive_site_selection='INCOMPLETE_SITE_ROOT'
                throw ('Identified site is missing required '+$required+'. Obtain full website files backup.')
            }
        }
        if($isZip){
            $one=@($entries | Where-Object {$_.FullName -ceq $lookup[$selected]})
            if($one.Count -ne 1){throw 'Cannot resolve unique application bootstrap entry.'}
            if($one[0].Length -gt 1048576){throw 'Unexpectedly large bootstrap file.'}
            $reader=[System.IO.StreamReader]::new($one[0].Open())
            try{$versionText=$reader.ReadToEnd()}finally{$reader.Dispose()}
        }else{
            $versionText=((& $tar.Source -xOzf $archivePath $lookup[$selected] 2>$null) -join [Environment]::NewLine)
            if($LASTEXITCODE -ne 0 -or $versionText.Length -gt 1048576){
                throw 'Cannot privately read installed application version from the selected site.'
            }
        }
        if($versionText -notmatch "const\s+APP_VERSION\s*=\s*'([^']+)'"){
            throw 'Identified site lacks NONIUSWTAX APP_VERSION signature.'
        }
        $script:report.code_archive_version=$Matches[1]
        $script:report.code_archive_selected_root_file_count=@($lookup.Keys | Where-Object {
            $_.StartsWith($prefix,[System.StringComparison]::OrdinalIgnoreCase)
        }).Count
        $script:report.code_archive_has_private_config=$lookup.ContainsKey($prefix+'config.local.php')
        $script:report.code_archive_has_storage=[bool](@($lookup.Keys | Where-Object {
            $_.StartsWith($prefix+'storage/',[System.StringComparison]::OrdinalIgnoreCase)
        }).Count)
        Write-Host ('Archive structural inspection: '+$script:report.code_archive_app_root_candidates+
          ' application root(s); '+$script:report.code_archive_expected_domain_candidates+
          ' expected-domain match(es).')
    }finally{
        if($null -ne $z){$z.Dispose()}
    }
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
    # Filename-derived time is a useful hint, not proof of a consistent snapshot.
    $sqlT=[regex]::Match([System.IO.Path]::GetFileName($sql),'(?<!\d)20\d{12}(?!\d)')
    $siteT=[regex]::Match([System.IO.Path]::GetFileName($siteArchive),'(?<!\d)20\d{12}(?!\d)')
    if($sqlT.Success -and $siteT.Success){
        $same=$sqlT.Value -ceq $siteT.Value
        $report.database_and_site_backup_timestamps_match=$same
        $report.backup_pair_timestamp_status=if($same){'FILENAME_TIMESTAMPS_MATCH_REVIEW_REQUIRED'}else{'DIFFERENT_TIMESTAMPS_REVIEW_REQUIRED'}
        if(!$same){
            Write-Host 'NOTICE: SQL and site archives are from different times. A read-only no-change history review is required for independent R1 approval.' -ForegroundColor Yellow
        }
    }else{
        $report.backup_pair_timestamp_status='TIMESTAMP_NOT_PRESENT_IN_BOTH_NAMES'
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
