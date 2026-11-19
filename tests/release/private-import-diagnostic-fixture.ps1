# CI-ONLY fictional data. No actual database, log, Hostinger or credentials.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$fixture=Join-Path $env:RUNNER_TEMP 'nonius-import-classification-fictional'
New-Item -Path $fixture -ItemType Directory -Force | Out-Null
$log=Join-Path $fixture 'fictional-sql-import-private.log'
$out=Join-Path $fixture 'classification.json'
$summary=Join-Path $fixture 'fictional-summary.json'
$secret='DO_NOT_LEAK_FICTIONAL_SUPPLIER_TIN_98765'
$rows=@(
  ' Container nonius-fake-inspector-run Creating',
  ('mariadb: ERROR 1227 (42000) at line 394: Access denied on a fictional user; '+$secret),
  ('ERROR 1273 (HY000) at line 900: Unknown collation ' +$secret),
  ('ERROR 9999 (HY000) at line 901: SECRET_FROM_SQL_STATEMENT '+$secret),
  ('INSERT INTO private_supplier VALUES ('''+$secret+''');')
)
[System.IO.File]::WriteAllLines($log,$rows,[System.Text.UTF8Encoding]::new($false))
[System.IO.File]::WriteAllText($summary,'{"report_format":"NONIUS_PRIVATE_BACKUP_RESTORE_V1","sql_import_docker_exit_code":1}')
& (Join-Path $root 'scripts\Diagnose-PrivateImportLog.ps1') -LogPath $log -RestoreSummaryPath $summary -OutputPath $out
if($LASTEXITCODE -ne 0){throw 'Fictional local import classification returned nonzero.'}
$r=Get-Content -LiteralPath $out -Raw|ConvertFrom-Json
$json=Get-Content -LiteralPath $out -Raw
if($json.Contains($secret) -or $json.Contains('INSERT INTO') -or $json.Contains('private_supplier') -or
   $json.Contains('line 394') -or $json.Contains('nonius-fake-inspector-run')){
   throw 'Private raw log text leaked to diagnostic summary.'
}
if($r.diagnostic_status -cne 'CLASSIFIED_MYSQL_ERROR_CODES' -or
   @($r.mysql_error_codes).Count -ne 3 -or
   @($r.mysql_error_codes|Where-Object {$_.code -eq '1227' -and $_.category -eq 'PRIVILEGE_OR_DEFINER_RESTRICTION'}).Count -ne 1 -or
   @($r.mysql_error_codes|Where-Object {$_.code -eq '1273' -and $_.category -eq 'UNKNOWN_COLLATION'}).Count -ne 1 -or
   @($r.mysql_error_codes|Where-Object {$_.code -eq '9999' -and $_.category -eq 'UNCLASSIFIED_MYSQL_ERROR'}).Count -ne 1 -or
   $r.docker_progress_marker_count -ne 1 -or
   $r.raw_log_contents_shared -ne $false -or
   $r.reported_backup_sql_import_exit_code -ne 1 -or
   $r.production_release -ne 'NO_GO_PENDING_R1_TO_R8'){
   throw 'Invalid or incomplete confidential import error classification.'
}
Write-Host 'PASS: private import error log classified by error codes, no sensitive lines or identifiers emitted.'
$cleanLog=Join-Path $fixture 'fictional-only-docker-progress.log'
[System.IO.File]::WriteAllLines($cleanLog,@('Container fictional-inspector Creating','Container fictional-inspector Created'))
& (Join-Path $root 'scripts\Diagnose-PrivateImportLog.ps1') -LogPath $cleanLog -RestoreSummaryPath $summary -OutputPath $out
if($LASTEXITCODE -ne 0){throw 'Fictional progress-only file failed classification.'}
$progress=Get-Content -LiteralPath $out -Raw|ConvertFrom-Json
if($progress.diagnostic_status -ne 'NO_RECOGNIZED_ERROR_IN_PRIVATE_LOG' -or
   @($progress.mysql_error_codes).Count -ne 0 -or
   $progress.docker_progress_marker_count -ne 2){
  throw 'Docker progress lines must not be classified as SQL errors.'
}
Write-Host 'PASS: progress-only output not mislabeled as SQL error.'
exit 0
