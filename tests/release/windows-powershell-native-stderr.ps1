# CI-only: all inputs are FICTIONAL; no Hostinger or Docker access.
# Exercise the same helper used by R1 recovery under Windows PowerShell 5.1.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot '..\..\scripts\PrivateNativeOutput.ps1')
$privateDir=Join-Path $env:RUNNER_TEMP 'nonius-ci-fake-native-stderr'
New-Item -ItemType Directory -Path $privateDir -Force | Out-Null

$okLog=Join-Path $privateDir 'fake-import.log'
$okCmd=@('/d','/c','echo Container fictional-inspector Creating 1>&2 & echo FICTIONAL_IMPORT_DONE & exit /b 0')
$ok=Invoke-PrivateNativeLogged -Program 'cmd.exe' -Arguments $okCmd -PrivateLogPath $okLog
if($ok.ExitCode -ne 0){
    Write-Host ('Synthetic native test returned code '+$ok.ExitCode)
    Write-Host (Get-Content -LiteralPath $okLog -Raw)
    throw 'Nonfatal native stderr incorrectly aborted the simulated import.'
}
if($ok.Stdout.Count -ne 0){throw 'Private import emitted captured output instead of keeping it in private log.'}
$okText=Get-Content -LiteralPath $okLog -Raw
if($okText -notmatch 'Container fictional-inspector Creating' -or
   $okText -notmatch 'FICTIONAL_IMPORT_DONE'){
    throw 'Native stderr/stdout were not both stored in private log.'
}
Write-Host 'PASS: Windows PowerShell 5.1 did not treat ordinary Docker-like stderr as an import failure.'

$metadataLog=Join-Path $privateDir 'fake-metadata.log'
$metadataCmd=@('/d','/c','echo Container fake Creating 1>&2 & echo FICTIONAL_AGGREGATES_ONLY & exit /b 0')
$metadata=Invoke-PrivateNativeLogged -Program 'cmd.exe' -Arguments $metadataCmd -PrivateLogPath $metadataLog -CaptureStdout
if($metadata.ExitCode -ne 0 -or $metadata.Stdout.Count -ne 1 -or
   ([string]$metadata.Stdout[0]).Trim() -cne 'FICTIONAL_AGGREGATES_ONLY'){
    Write-Host ('FICTIONAL CI metadata diagnostic: exit='+$metadata.ExitCode+'; rows='+$metadata.Stdout.Count)
    throw 'Expected only aggregate stdout from metadata query.'
}
$metaText=Get-Content -LiteralPath $metadataLog -Raw
if($metaText -notmatch 'Container fake Creating' -or
   $metaText -match 'FICTIONAL_AGGREGATES_ONLY'){
    throw 'Metadata stderr was not properly separated into private local log.'
}
Write-Host 'PASS: metadata stdout only contains fictional aggregates; stderr stays in private file.'

$failLog=Join-Path $privateDir 'fake-failure.log'
$failCmd=@('/d','/c','echo FICTIONAL_SQL_IMPORT_FAILURE 1>&2 & exit /b 7')
$failed=Invoke-PrivateNativeLogged -Program 'cmd.exe' -Arguments $failCmd -PrivateLogPath $failLog
if($failed.ExitCode -ne 7){throw 'Real nonzero native process exit code was not preserved.'}
if((Get-Content -LiteralPath $failLog -Raw) -notmatch 'FICTIONAL_SQL_IMPORT_FAILURE'){
    throw 'Fictional SQL error did not remain in private log.'
}
Write-Host 'PASS: actual simulated SQL importer failure remains an exit-code 7, not silently accepted.'
Write-Host 'ALL PASS: fictional PowerShell-native-stream regression only. No production data accessed.'
# A deliberately simulated exit 7 above leaves LASTEXITCODE=7 globally.
# All assertions passed, so explicitly return success for GitHub Actions.
exit 0
