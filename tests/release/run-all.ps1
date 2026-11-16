# Single-command NONIUSWTAX disposable release suite for Windows PowerShell.
# Run from the repository root:
# powershell -ExecutionPolicy Bypass -File .\tests\release\run-all.ps1
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $root
$env:COMPOSE_PROJECT_NAME = 'nonius-release-verification'
$baseArgs = @('compose', '-f', 'compose.release-test.yml')
$passed = $false
function Run-Docker([string[]] $runArgs) {
    & docker @runArgs
    if ($LASTEXITCODE -ne 0) { throw ('Docker command failed with exit code ' + $LASTEXITCODE) }
}
Write-Host 'NONIUSWTAX: one-command local release verification' -ForegroundColor Cyan
Write-Host 'Uses a fresh synthetic database; never your production database.'
try {
    Run-Docker (@('info', '--format', '{{.ServerVersion}}'))
    Run-Docker ($baseArgs + @('down', '--volumes', '--remove-orphans'))
    Run-Docker ($baseArgs + @('build', 'web', 'browser'))
    Run-Docker ($baseArgs + @('up', '-d', '--wait', 'db', 'web'))
    Write-Host 'Running source PHP lint and security/calculation smoke tests...' -ForegroundColor Cyan
    $smoke = 'find /var/www/html -type f -name "*.php" -print0 | xargs -0 -n1 php -l && php tests/calculation-smoke.php && php tests/safety-smoke.php && php tests/sql-guard-smoke.php'
    Run-Docker ($baseArgs + @('run', '--rm', '--no-deps', 'web', 'sh', '-ec', $smoke))
    Write-Host 'Running combined Playwright, permissions, migration, DB and HTTP tests...' -ForegroundColor Cyan
    Run-Docker ($baseArgs + @('run', '--rm', 'browser'))
    $passed = $true
} catch {
    Write-Host ('TEST PACKAGE FAILED: ' + $_.Exception.Message) -ForegroundColor Red
} finally {
    Write-Host 'Stopping disposable containers and deleting their test database...' -ForegroundColor Yellow
    $cleanupArgs = $baseArgs + @('down', '--volumes', '--remove-orphans')
    & docker @cleanupArgs
}
if ($passed) {
    Write-Host 'PASS: Automated local test package completed.' -ForegroundColor Green
    Write-Host ('HTML report: ' + (Join-Path $root 'test-results\html\index.html'))
    Write-Host ('JSON report: ' + (Join-Path $root 'test-results\results.json'))
    Write-Host 'NOTE: BIR tax sign-off, PDF visual inspection, production backup restore and real load tests remain manual release gates.'
    exit 0
}
Write-Host ('When available, check: ' + (Join-Path $root 'test-results\html\index.html'))
exit 1
