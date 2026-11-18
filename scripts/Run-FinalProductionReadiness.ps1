# NONIUSWTAX: single remaining historical+approval release readiness command.
# Reuses already passing 36/36 Windows acceptance; NEVER replays all tests.
# NEVER deploys or connects to Hostinger. Private SQL stays off Git and chat.
param(
  [Parameter(Mandatory=$true)][string]$PrivateSqlPath,
  [switch]$ApprovedForLocalPrivateData,
  [string]$ApprovalRecordPath = '.\private-release-evidence\production-approval.json'
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
if(!$ApprovedForLocalPrivateData){
    throw 'Institutional authorization for local private historical data processing is required.'
}
$acceptFile=Join-Path $root 'acceptance-results\acceptance-summary.json'
if(!(Test-Path -LiteralPath $acceptFile)){throw 'Existing 36/36 Windows acceptance summary is missing. Do not begin private data processing.'}
$accept=Get-Content -LiteralPath $acceptFile -Raw|ConvertFrom-Json
if($accept.package -ne 'NONIUSWTAX_ONE_COMMAND_ACCEPTANCE_V1' -or
   $accept.automated_result -ne 'AUTOMATED_PASS_PENDING_MIGRATION_037_REVIEW' -or
   $accept.exact_windows_zip_tested -ne $true -or
   $accept.candidate_sha256 -cne 'e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a'){
    throw 'Pinned exact-Windows acceptance evidence does not match the required tested release candidate.'
}
$engine=if($PSVersionTable.PSEdition -eq 'Core'){'pwsh'}else{'powershell.exe'}
$argsBase=@('-NoProfile')
if($PSVersionTable.PSEdition -ne 'Core'){$argsBase+=@('-ExecutionPolicy','Bypass')}
Write-Host 'NONIUSWTAX: FINAL INDEPENDENT PRODUCTION GO/NO-GO EVIDENCE RUN' -ForegroundColor Cyan
Write-Host 'Reusing prior 36/36 test evidence; no repeated synthetic suite.'
Write-Host 'STEP 1/2: Closed-box private original DB migration 037 and restore. No web, no exposed ports.'
& $engine @argsBase -File (Join-Path $PSScriptRoot 'Run-PrivateHistoricalDrill.ps1') -PrivateSqlPath $PrivateSqlPath -ApprovedForLocalPrivateData
$historyExit=[int]$LASTEXITCODE
if($historyExit -ne 0){
    Write-Host 'NO-GO: private historical migration/restore failed. Existing synthetic acceptance remains valid.' -ForegroundColor Red
    exit 1
}
Write-Host 'STEP 2/2: Evaluate ALL evidence and independent written approvals. NO deployment.'
& $engine @argsBase -File (Join-Path $PSScriptRoot 'Check-FinalGoNoGo.ps1') -ApprovalRecordPath $ApprovalRecordPath
$goExit=[int]$LASTEXITCODE
$path=Join-Path $root 'private-release-evidence\final-gate-report.json'
Write-Host ('Final review report: '+$path)
Write-Host 'Never upload historical SQL or production credentials. Inspect private signoffs outside Git.'
if($goExit -eq 0){
    Write-Host 'Recorded GO evidence exists, but actual signatures and approved GA artifact must be independently verified before the separate authorized cutover.' -ForegroundColor Yellow
    exit 0
}
if($goExit -eq 2){
    Write-Host 'NO-GO/PENDING: historical technical rehearsal passed; remaining finance/security/hosting/GA signoffs still required.' -ForegroundColor Yellow
    exit 2
}
Write-Host 'NO-GO: final gate checker error.' -ForegroundColor Red
exit 1
