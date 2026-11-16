# NONIUSWTAX staging-only updater rehearsal. NEVER touch the live site.
# Run on Windows from the release/0.4.57-rc1-preparation branch:
# powershell -ExecutionPolicy Bypass -File .\scripts\Test-StagingCandidate.ps1
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$candidateName = 'noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip'
$candidate = Join-Path (Join-Path $root 'release-out') $candidateName
$results = Join-Path $root 'staging-results'
if (!(Test-Path -LiteralPath $candidate)) { throw "Missing built candidate ZIP: $candidate" }
# Fail before starting Docker if ZIP, version, source SHA or any file hash differs.
& (Join-Path $PSScriptRoot 'Verify-ReleaseCandidate.ps1') -PackagePath $candidate
New-Item -ItemType Directory -Force -Path $results | Out-Null
foreach ($name in @('results.json','staging-upgrade.json','staging-rollback.json','staging-summary.json')) {
    $f = Join-Path $results $name
    if (Test-Path -LiteralPath $f) { Remove-Item -LiteralPath $f -Force }
}
$env:COMPOSE_PROJECT_NAME = 'nonius-rc-staging'
$baseArgs = @('compose', '-f', 'compose.release-test.yml', '-f', 'compose.staging-candidate.yml')
function Run-Docker([string[]]$arguments) {
    & docker @arguments
    if ($LASTEXITCODE -ne 0) { throw ('Docker command failed: exit code ' + $LASTEXITCODE) }
}
$playwrightOk = $false
$upgradeOk = $false
$codeRestoreOk = $false
$dbRestoreOk = $false
$rollbackOk = $false
Write-Host 'NONIUSWTAX: ISOLATED CANDIDATE UPGRADE AND ROLLBACK REHEARSAL' -ForegroundColor Cyan
Write-Host 'Dedicated disposable synthetic Docker project only. Production NOT involved.'
try {
    Run-Docker (@('info', '--format', '{{.ServerVersion}}'))
    Run-Docker ($baseArgs + @('down', '--volumes', '--remove-orphans'))
    Run-Docker ($baseArgs + @('build', 'web', 'browser'))
    Run-Docker ($baseArgs + @('up', '-d', '--wait', 'db', 'web'))
    # The updater runs under www-data and must be able to write into the
    # dedicated docker volume used to preserve code/database rollback backups.
    Run-Docker ($baseArgs + @('exec', '-T', 'web', 'sh', '-ec',
      'mkdir -p /var/www/html/storage/backups && chown -R www-data:www-data /var/www/html/storage/backups'))
    Write-Host 'STAGING 1/3: Install 0.4.56 and prepare fully synthetic records...' -ForegroundColor Cyan
    Run-Docker ($baseArgs + @('run', '--rm', 'browser'))
    $playwrightOk = $true
    Write-Host 'STAGING 2/3: Upload, review and install EXACT verified RC ZIP...' -ForegroundColor Cyan
    Run-Docker ($baseArgs + @('run', '--rm', '--no-deps', 'browser', 'node', '/suite/candidate-upgrade.cjs'))
    $upgradeOk = $true
    Write-Host 'STAGING 3/3: Restore original code + updater-created pre-upgrade DB backup...' -ForegroundColor Cyan
    Run-Docker ($baseArgs + @('exec', '-T', 'web', 'php', 'tests/release/candidate-restore-code.php'))
    $codeRestoreOk = $true
    $normalizer = 'tr -d ''\015'' < /suite-tests/candidate-restore-db.sh | bash'
    Run-Docker ($baseArgs + @('exec', '-T', 'db', 'sh', '-ec', $normalizer))
    $dbRestoreOk = $true
    Run-Docker ($baseArgs + @('run', '--rm', '--no-deps', 'browser', 'node', '/suite/candidate-rollback.cjs'))
    $rollbackOk = $true
} catch {
    Write-Host ('STAGING REHEARSAL FAILED: ' + $_.Exception.Message) -ForegroundColor Red
} finally {
    Write-Host 'Deleting ONLY disposable nonius-rc-staging Docker containers and volumes...' -ForegroundColor Yellow
    $cleanup = $baseArgs + @('down', '--volumes', '--remove-orphans')
    & docker @cleanup
}
$browserResults = Join-Path $results 'results.json'
$playwrightSummary = [ordered]@{ passed = 0; failed = 0; skipped = 0 }
if (Test-Path $browserResults) {
    $report = Get-Content -LiteralPath $browserResults -Raw | ConvertFrom-Json
    $playwrightSummary.passed = [int]$report.stats.expected
    $playwrightSummary.failed = [int]$report.stats.unexpected
    $playwrightSummary.skipped = [int]$report.stats.skipped
}
$allOk = $playwrightOk -and $upgradeOk -and $codeRestoreOk -and $dbRestoreOk -and $rollbackOk
$summary = [ordered]@{
    timestamp_utc = (Get-Date).ToUniversalTime().ToString('o')
    rehearsal_status = if ($allOk) {'PASS'} else {'FAIL'}
    candidate_version = '0.4.57-rc.1'
    candidate_sha256 = 'e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a'
    source_commit = 'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
    synthetic_installation_and_12_tests = $playwrightOk
    in_app_update_review_and_install = $upgradeOk
    updater_created_code_backup_restored = $codeRestoreOk
    updater_created_database_backup_restored = $dbRestoreOk
    independent_post_rollback_verification = $rollbackOk
    playwright = $playwrightSummary
    actual_existing_installation_clone_tested = $false
    production_approval = 'PENDING'
    note = 'Synthetic-only isolated Docker rehearsal. An independently sanitized existing-installation clone and official BIR, Hostinger and authorization gates are still required.'
}
$utf8 = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Join-Path $results 'staging-summary.json'),
  (($summary | ConvertTo-Json -Depth 8) + "`n"), $utf8)
Write-Host ('Candidate rehearsal report: ' + (Join-Path $results 'staging-summary.json'))
if ($allOk) {
    Write-Host 'PASS: Synthetic staging ZIP review/install and full code+DB rollback completed.' -ForegroundColor Green
    Write-Host 'Still NOT approved for any production deployment.' -ForegroundColor Yellow
    exit 0
}
Write-Host 'FAIL: Read staging-results/staging-upgrade.json and staging-rollback.json (when generated).' -ForegroundColor Red
exit 1
