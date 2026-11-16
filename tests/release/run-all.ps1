# NONIUSWTAX FULL RELEASE VERIFICATION — ONE COMMAND, SYNTHETIC DATA ONLY
# powershell -ExecutionPolicy Bypass -File .\tests\release\run-all.ps1
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $root
$env:COMPOSE_PROJECT_NAME = 'nonius-release-verification'
$baseArgs = @('compose', '-f', 'compose.release-test.yml')
$results = Join-Path $root 'test-results'
New-Item -ItemType Directory -Force -Path $results | Out-Null
# Invalidate current-run status files; never accept old successes as this run's evidence.
foreach ($name in @('results.json','backup-restore.json','performance.json','source-smoke.status','release-summary.json','release-report.html','bir2307-rendered-synthetic.pdf','bir2307-visual-review.png')) {
    $target = Join-Path $results $name
    if (Test-Path $target) { Remove-Item -LiteralPath $target -Force }
}
function Run-Docker([string[]] $runArgs) {
    & docker @runArgs
    if ($LASTEXITCODE -ne 0) { throw ('Docker command failed: exit code ' + $LASTEXITCODE) }
}
$sourcePassed = $false
$browserPassed = $false
$restorePassed = $false
$reportPassed = $false
Write-Host 'NONIUSWTAX: ONE FULL RELEASE VERIFICATION PACKAGE' -ForegroundColor Cyan
Write-Host 'This run creates a new disposable database. Never production data.'
try {
    Run-Docker (@('info', '--format', '{{.ServerVersion}}'))
    Run-Docker ($baseArgs + @('down', '--volumes', '--remove-orphans'))
    Run-Docker ($baseArgs + @('build', 'web', 'browser'))
    Run-Docker ($baseArgs + @('up', '-d', '--wait', 'db', 'web'))
    Write-Host 'STAGE 1/4: PHP, golden tax calculation, and source security checks...' -ForegroundColor Cyan
    try {
        $smoke = 'php tests/release/php-lint.php && php tests/release/tax-golden.php && php tests/calculation-smoke.php && php tests/safety-smoke.php && php tests/sql-guard-smoke.php'
        Run-Docker ($baseArgs + @('run', '--rm', '--no-deps', 'web', 'sh', '-ec', $smoke))
        Set-Content -LiteralPath (Join-Path $results 'source-smoke.status') -Value 'PASS' -NoNewline
        $sourcePassed = $true
    } catch { Write-Host ('SOURCE CHECK FAILED: ' + $_.Exception.Message) -ForegroundColor Red }
    Write-Host 'STAGE 2/4: All Playwright, transactions, PDF, database and 10k record performance checks...' -ForegroundColor Cyan
    try {
        Run-Docker ($baseArgs + @('run', '--rm', 'browser'))
        $browserPassed = $true
    } catch { Write-Host ('BROWSER/INTEGRATION CHECK FAILED: ' + $_.Exception.Message) -ForegroundColor Red }
    Write-Host 'STAGE 3/4: Full synthetic SQL backup and fresh restore, including triggers...' -ForegroundColor Cyan
    try {
        Run-Docker ($baseArgs + @('exec', '-T', 'db', 'bash', '/suite-tests/backup-restore.sh'))
        $restorePassed = $true
    } catch { Write-Host ('BACKUP/RESTORE CHECK FAILED: ' + $_.Exception.Message) -ForegroundColor Red }
    Write-Host 'STAGE 4/4: Generate one consolidated HTML/JSON release report...' -ForegroundColor Cyan
    try {
        Run-Docker ($baseArgs + @('run', '--rm', '--no-deps', 'browser', 'node', '/suite/report.cjs'))
        $reportPassed = $true
    } catch { Write-Host ('FINAL REPORT: One or more automated gates failed: ' + $_.Exception.Message) -ForegroundColor Red }
} catch {
    Write-Host ('TEST ENVIRONMENT FAILED: ' + $_.Exception.Message) -ForegroundColor Red
} finally {
    Write-Host 'Removing ONLY the disposable Docker release-test containers and volume...' -ForegroundColor Yellow
    $cleanupArgs = $baseArgs + @('down', '--volumes', '--remove-orphans')
    & docker @cleanupArgs
}
Write-Host ('Consolidated report: ' + (Join-Path $results 'release-report.html'))
Write-Host ('Consolidated JSON: ' + (Join-Path $results 'release-summary.json'))
if ($sourcePassed -and $browserPassed -and $restorePassed -and $reportPassed) {
    Write-Host 'AUTOMATED PACKAGE: PASS. BIR, deployment security and authorization remain PENDING.' -ForegroundColor Green
    exit 0
}
Write-Host 'AUTOMATED PACKAGE: FAILED. Review release-report.html and Playwright details.' -ForegroundColor Red
exit 1
