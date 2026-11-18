# NONIUSWTAX one-command, comprehensive synthetic acceptance package.
# Run from Windows repository root:
# powershell -ExecutionPolicy Bypass -File .\scripts\Run-FullAcceptance.ps1
# NO Hostinger access, NO real database import, NO production deployment.
param(
    [string]$SchemaReportPath = '.\schema-preflight-results\existing-schema-report.json',
    [switch]$CICrossPlatformZip
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$engine=if($PSVersionTable.PSEdition -eq 'Core'){'pwsh'}else{'powershell.exe'}
$sourceSHA='adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
$zipSHA='e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a'
$effectiveZIP=$zipSHA
if($CICrossPlatformZip -and ($env:GITHUB_ACTIONS -ne 'true' -or $PSVersionTable.PSEdition -ne 'Core')){
    throw 'Cross-platform ZIP test mode is reserved for synthetic Linux GitHub Actions; use exact pinned archive on Windows.'
}
$zipName='noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip'
$zipPath=Join-Path $root ('release-out\' + $zipName)
$out=Join-Path $root 'acceptance-results'
$logs=Join-Path $out 'logs'
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$summaryFile=Join-Path $out 'acceptance-summary.json'
$htmlFile=Join-Path $out 'acceptance-report.html'
foreach($p in @($summaryFile,$htmlFile)){if(Test-Path -LiteralPath $p){Remove-Item -LiteralPath $p -Force}}
$utf8=New-Object System.Text.UTF8Encoding($false)
$started=(Get-Date).ToUniversalTime().ToString('o')
$stageRows=@()
$errors=@()
$schema=$null
$schemaPath=$null
$ready=$false

function ReadJSON([string]$path) {
    if(!(Test-Path -LiteralPath $path -PathType Leaf)){return $null}
    try{return Get-Content -LiteralPath $path -Raw | ConvertFrom-Json}
    catch{return $null}
}
function RunStage([string]$name,[string]$relativeScript,[string]$logFile,[string[]]$options=@()) {
    $logPath=Join-Path $logs $logFile
    if(Test-Path -LiteralPath $logPath){Remove-Item -LiteralPath $logPath -Force}
    $file=Join-Path $root $relativeScript
    $sw=[System.Diagnostics.Stopwatch]::StartNew()
    $code=901
    Write-Host ('RUNNING: '+$name) -ForegroundColor Cyan
    if(!(Test-Path -LiteralPath $file)){
        [System.IO.File]::WriteAllText($logPath,'Missing test script: '+$relativeScript,$utf8)
    } else {
        $cli=@('-NoProfile')
        if($PSVersionTable.PSEdition -ne 'Core'){$cli+=@('-ExecutionPolicy','Bypass')}
        $cli+=@('-File',$file)
        $cli+=$options
        $previous=$ErrorActionPreference
        try {
            $ErrorActionPreference='Continue'
            & $engine @cli *>&1 | Out-File -LiteralPath $logPath -Encoding UTF8
            $code=[int]$LASTEXITCODE
        }catch{
            Add-Content -LiteralPath $logPath -Value ('Runner error: '+$_.Exception.Message)
        }finally{$ErrorActionPreference=$previous}
    }
    $sw.Stop()
    $result=[pscustomobject][ordered]@{
        name=$name
        exit_code=$code
        seconds=[math]::Round($sw.Elapsed.TotalSeconds,1)
        log=('logs/'+$logFile)
    }
    $script:stageRows+=@($result)
    Write-Host ('FINISHED: '+$name+' (exit '+$code+')')
    return $result
}
function HtmlEncode([string]$value){return [System.Net.WebUtility]::HtmlEncode($value)}

Write-Host 'NONIUSWTAX: ONE BIG ACCEPTANCE TEST' -ForegroundColor Cyan
Write-Host 'One command, three isolated Docker/Playwright suites, one overall report.'
Write-Host 'All testing is local, synthetic and NON-PRODUCTION.'
try {
    $schemaPath=(Resolve-Path -LiteralPath $SchemaReportPath).Path
    if([System.IO.Path]::GetExtension($schemaPath).ToLowerInvariant() -ne '.json' -or
       (Get-Item -LiteralPath $schemaPath).Length -gt 10000000){
        throw 'Use only the small structure-only existing-schema-report.json, never an SQL dump.'
    }
    $schema=ReadJSON $schemaPath
    if($null -eq $schema -or
       $schema.report_type -ne 'SCHEMA_ONLY_NO_PRODUCTION_RECORDS' -or
       $schema.schema.format -ne 'NONIUS_SCHEMA_PREFLIGHT_V1' -or
       $schema.source_data_rows_inspected -ne $false -or
       $schema.source_data_sanitized -ne $false -or
       $schema.real_installed_upgrade_tested -ne $false -or
       $schema.production_approval -ne 'PENDING' -or
       @($schema.schema.tables).Count -lt 6){
        throw 'This is not the approved schema-only metadata report.'
    }
    if(!(Test-Path -LiteralPath $zipPath -PathType Leaf)){
        Write-Host 'Pinned candidate ZIP absent. Building locally from the verified Git commit...'
        $build=RunStage 'Build pinned staging-only candidate ZIP' 'scripts/Build-ReleaseCandidate.ps1' '00-build-candidate.log'
        if($build.exit_code -ne 0){throw 'Candidate build failed. See 00-build-candidate.log.'}
    }
    $actualHash=(Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if($actualHash -cne $zipSHA -and !$CICrossPlatformZip){
        throw 'Candidate ZIP hash differs from owner/Windows CI verified SHA-256. Stop.'
    }
    if($CICrossPlatformZip){
        # Linux .NET compression may produce different archive bytes. This CI-only
        # run still verifies the pinned Git source and every manifest file hash.
        # NEVER claim it tested the exact Windows ZIP distributed to the owner.
        $effectiveZIP=$actualHash
        $env:NONIUS_TEST_CI_MODE='true'
        $env:NONIUS_TEST_ZIP_SHA256=$effectiveZIP
        Write-Host 'CI ONLY: cross-platform repacked ZIP verified by exact Git source and per-file digest.' -ForegroundColor Yellow
    }
    $verify=RunStage 'Verify candidate SHA-256, manifest and 27 runtime files' 'scripts/Verify-ReleaseCandidate.ps1' '00-verify-candidate.log' @('-PackagePath',$zipPath)
    if($verify.exit_code -ne 0){throw 'Candidate verification failed. See 00-verify-candidate.log.'}
    $ready=$true
}catch{
    $errors+=@($_.Exception.Message)
    Write-Host ('PRECHECK FAILED: '+$_.Exception.Message) -ForegroundColor Red
}
if($ready){
    $runFull=RunStage 'Full source/tax/PDF/12 Playwright/load/database backup-restore' 'tests/release/run-all.ps1' '01-full-release.log'
    $runCandidate=RunStage 'Exact candidate upgrade with complete code and SQL rollback' 'scripts/Test-StagingCandidate.ps1' '02-candidate-and-rollback.log'
    $runSchema=RunStage 'Existing schema comparison and simulated migration 037 repair' 'scripts/Test-ExistingSchemaDockerPlaywright.ps1' '03-schema-and-migration.log' @('-SchemaReportPath',$schemaPath)
}
$full=if($ready){ReadJSON (Join-Path $root 'test-results\release-summary.json')}else{$null}
$candidate=if($ready){ReadJSON (Join-Path $root 'staging-results\staging-summary.json')}else{$null}
$comparison=if($ready){ReadJSON (Join-Path $root 'existing-compat-results\compatibility-summary.json')}else{$null}
$drift=if($ready){ReadJSON (Join-Path $root 'existing-compat-results\existing-schema-comparison.json')}else{$null}
$migration=if($ready){ReadJSON (Join-Path $root 'existing-compat-results\migration037-rehearsal.json')}else{$null}
$fullPass=($ready -and $runFull.exit_code -eq 0 -and $null -ne $full -and
           $full.automated_verification -eq 'PASS' -and $full.playwright.passed -eq 12 -and
           $full.playwright.failed -eq 0 -and $full.backup.status -eq 'passed' -and
           @($full.gates | Where-Object {$_.status -ne 'PASS'}).Count -eq 0)
$candidatePass=($ready -and $runCandidate.exit_code -eq 0 -and $null -ne $candidate -and
           $candidate.rehearsal_status -eq 'PASS' -and $candidate.candidate_sha256 -ceq $effectiveZIP -and
           $candidate.playwright.passed -eq 12 -and $candidate.in_app_update_review_and_install -eq $true -and
           $candidate.updater_created_code_backup_restored -eq $true -and
           $candidate.updater_created_database_backup_restored -eq $true -and
           $candidate.independent_post_rollback_verification -eq $true)
$schemaPass=($ready -and ($runSchema.exit_code -eq 0 -or $runSchema.exit_code -eq 2) -and
           $null -ne $comparison -and $null -ne $drift -and $null -ne $migration -and
           $comparison.playwright.passed -eq 12 -and $comparison.playwright.failed -eq 0 -and
           $comparison.synthetic_migration037_rehearsal_passed -eq $true -and
           $migration.status -eq 'passed' -and
           $migration.checks.trigger_restored -eq $true -and
           $migration.checks.closed_month_hash_tamper_rejected -eq $true -and
           $comparison.installed_schema_table_count -eq @($schema.schema.tables).Count -and
           $comparison.installed_schema_trigger_count -eq @($schema.schema.triggers).Count)
$structural=if($null -ne $drift){[string]$drift.status}else{'NOT_VERIFIED'}
$trigger='protect_checked_hash_period_update|transactions|BEFORE|UPDATE'
$known037=($schemaPass -and $structural -eq 'DRIFT_REVIEW_REQUIRED' -and
           $drift.missing_structure_items -eq 1 -and $drift.extra_structure_items -eq 0 -and
           $drift.changed_columns -eq 0 -and
           (@($drift.comparison.triggers.absent_from_installed) -contains $trigger))
$match=($schemaPass -and $structural -eq 'STRUCTURAL_MATCH')
$automatedPass=($fullPass -and $candidatePass -and $schemaPass)
if(!$automatedPass){$decision='AUTOMATED_TEST_FAILURE';$exitCode=1}
elseif($match){$decision='AUTOMATED_PASS_SCHEMA_MATCH';$exitCode=0}
elseif($known037){$decision='AUTOMATED_PASS_PENDING_MIGRATION_037_REVIEW';$exitCode=2}
else{$decision='AUTOMATED_PASS_UNEXPECTED_SCHEMA_DRIFT_REVIEW';$exitCode=2}
$gates=@(
    [ordered]@{name='Source, finance arithmetic, 12 browser, PDF, load and SQL backup/restore';status=if($fullPass){'PASS'}else{'FAIL'}},
    [ordered]@{name='Exact candidate ZIP install, SQL/code backup and rollback';status=if($candidatePass){'PASS'}else{'FAIL'}},
    [ordered]@{name='Structural comparison, Playwright and synthetic migration 037 repair';status=if($schemaPass){'PASS'}else{'FAIL'}},
    [ordered]@{name='Existing installed schema';status=if($match){'MATCH'}elseif($known037){'KNOWN_MIGRATION_037_GAP'}else{'REVIEW_REQUIRED'}},
    [ordered]@{name='Real hosting, finance compliance and production release approval';status='PENDING'}
)
$manual=@(
  'Independent BIR VAT/EWT/ATC calculation and printed 2307 sign-off',
  'Sanitized historical installed-database clone and its real upgrade/rollback',
  'Hostinger HTTPS, secrets, file access and identity restrictions',
  'Representative multi-user performance on target hardware',
  'Encrypted tested off-site backup, provenance and written release authorization'
)
$summary=[ordered]@{
    generated_at_utc=(Get-Date).ToUniversalTime().ToString('o')
    started_at_utc=$started
    package='NONIUSWTAX_ONE_COMMAND_ACCEPTANCE_V1'
    automated_result=$decision
    exit_code=$exitCode
    production_release='NO_GO_PENDING_MANUAL_APPROVAL'
    verified_source_commit=$sourceSHA
    candidate_version='0.4.57-rc.1'
    candidate_sha256=$effectiveZIP
    reference_windows_zip_sha256=$zipSHA
    ci_cross_platform_zip_repacked=[bool]$CICrossPlatformZip
    exact_windows_zip_tested=(!$CICrossPlatformZip)
    actual_production_database_accessed=$false
    real_installed_data_sanitized=$false
    real_installed_upgrade_tested=$false
    schema_source='READ_ONLY_STRUCTURE_METADATA_JSON'
    schema_export_sha256=if($null -ne $schema){$schema.source_export_sha256}else{$null}
    installed_table_count=if($null -ne $schema){@($schema.schema.tables).Count}else{$null}
    installed_trigger_count=if($null -ne $schema){@($schema.schema.triggers).Count}else{$null}
    schema_result=$structural
    known_037_gap=$known037
    migration_037_queried_by_automation=$false
    migration_037_owner_read_only_status='NO_ROW_REPORTED_2026-11-18'
    synthetic_migration_037_passed=$schemaPass
    stage_processes=$stageRows
    full_suite=if($null -ne $full){[ordered]@{playwright=$full.playwright;backup_status=$full.backup.status}}else{$null}
    candidate_suite=if($null -ne $candidate){[ordered]@{playwright=$candidate.playwright;status=$candidate.rehearsal_status}}else{$null}
    schema_suite=if($null -ne $comparison){[ordered]@{playwright=$comparison.playwright;missing=$comparison.missing_schema_items;extra=$comparison.extra_schema_items;changed_columns=$comparison.changed_column_definitions}}else{$null}
    preflight_errors=$errors
    gates=$gates
    manual_approval_pending=$manual
}
[System.IO.File]::WriteAllText($summaryFile,(($summary | ConvertTo-Json -Depth 16)+[Environment]::NewLine),$utf8)
$rows=($gates | ForEach-Object {'<tr><td>'+(HtmlEncode $_.name)+'</td><td><strong>'+(HtmlEncode $_.status)+'</strong></td></tr>'}) -join ''
$manualHtml=($manual | ForEach-Object {'<li>'+(HtmlEncode $_)+'</li>'}) -join ''
$decisionHtml=HtmlEncode $decision
$html=@"
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>NONIUSWTAX Acceptance</title>
<style>body{font:16px/1.55 system-ui,Arial,sans-serif;color:#172536;max-width:950px;margin:40px auto;padding:0 20px}h1{font-size:27px}h2{font-size:20px;margin-top:28px}table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #dce2e9;padding:12px}th{background:#f4f6f9}.note{padding:16px;background:#f3f5f8;border:1px solid #d3dce7;border-radius:8px}a{color:#075c9b}li{margin:7px 0}</style></head>
<body><h1>NONIUSWTAX — Comprehensive One-Command Test</h1>
<p>Generated $(HtmlEncode $summary.generated_at_utc) · local Docker + Playwright + MariaDB</p>
<div class="note"><strong>Automated result: $decisionHtml</strong><p><strong>Production status: NO-GO until authorized approval.</strong></p>
<p>Exact source commit $(HtmlEncode $sourceSHA) and release candidate v0.4.57-rc.1.</p></div>
<h2>Consolidated results</h2><table><thead><tr><th>Gate</th><th>Result</th></tr></thead><tbody>$rows</tbody></table>
<h2>Detailed local evidence</h2><p>
<a href="../test-results/release-report.html">Source / tax / PDF / restore</a> ·
<a href="../staging-results/staging-summary.json">Candidate install and rollback</a> ·
<a href="../existing-compat-results/compatibility-summary.json">Schema &amp; migration summary</a> ·
<a href="../existing-compat-results/migration037-rehearsal.json">Migration 037 regression</a> ·
<a href="acceptance-summary.json">One machine-readable summary</a></p>
<p><a href="logs/01-full-release.log">Full suite log</a> · <a href="logs/02-candidate-and-rollback.log">Upgrade log</a> · <a href="logs/03-schema-and-migration.log">Schema log</a></p>
<h2>Still requiring human authorization</h2><ul>$manualHtml</ul>
<p>Migration 037's missing live history row was separately confirmed by the owner in read-only phpMyAdmin on 2026-11-18, not queried by this suite.</p>
<p>No live site or real taxpayer/supplier transaction data is accessed by this package.</p></body></html>
"@
[System.IO.File]::WriteAllText($htmlFile,$html,$utf8)
Write-Host ('OVERALL: '+$decision) -ForegroundColor $(if($exitCode -eq 1){'Red'}else{'Yellow'})
Write-Host ('HTML: '+$htmlFile)
Write-Host ('JSON: '+$summaryFile)
Write-Host 'Exit 0=technical pass/schema match; 2=technical pass/schema review; 1=technical failure.'
Write-Host 'PRODUCTION: NO-GO. Never upload this test package or candidate ZIP to the live site.'
exit $exitCode
