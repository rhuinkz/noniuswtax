# NONIUSWTAX final release evidence gate. READ-ONLY; NEVER deploys to Hostinger.
# Does not rerun already-passing synthetic Docker tests or read raw database rows.
param(
    [string]$AcceptanceSummaryPath = '.\acceptance-results\acceptance-summary.json',
    [string]$PrivateHistoricalSummaryPath = '.\private-historical-results\private-historical-summary.json',
    [string]$ApprovalRecordPath = '.\private-release-evidence\production-approval.json'
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$expectedSource='adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
$expectedZip='e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a'
$gateNames=@(
  'private_historical_migration_restore',
  'approved_sanitization_no_leaks',
  'sanitized_existing_installation_updater_and_rollback',
  'philippines_bir_tax_rules_and_rates',
  'physical_form_2307_review',
  'hostinger_https_secrets_roles_hardening',
  'privacy_impact_assessment_and_data_handling',
  'target_concurrent_load_and_latency_slo',
  'independent_encrypted_offsite_restore_rto_rpo',
  'final_exact_production_artifact_staging_rehearsal',
  'production_change_window_and_rollback_authority'
)
function ReadJson([string]$path){
    if(!(Test-Path -LiteralPath $path -PathType Leaf)){return $null}
    if((Get-Item -LiteralPath $path).Length -gt 2000000){return $null}
    try{return Get-Content -LiteralPath $path -Raw | ConvertFrom-Json}
    catch{return $null}
}
function HasValue($value){return $null -ne $value -and ![string]::IsNullOrWhiteSpace([string]$value)}
function IsISODate($value){
    if(!(HasValue $value)){return $false}
    $tmp=[datetimeoffset]::MinValue
    return [datetimeoffset]::TryParse([string]$value,[ref]$tmp)
}
$issues=New-Object 'System.Collections.Generic.List[string]'
$acceptance=ReadJson $AcceptanceSummaryPath
$hist=ReadJson $PrivateHistoricalSummaryPath
$approvals=ReadJson $ApprovalRecordPath
$acceptanceOk=$false
$historicalOk=$false
if($null -eq $acceptance){
    $issues.Add('Exact Windows one-command acceptance-summary.json is missing or invalid.') | Out-Null
}else{
    $acceptanceOk=(
       $acceptance.package -eq 'NONIUSWTAX_ONE_COMMAND_ACCEPTANCE_V1' -and
       $acceptance.verified_source_commit -ceq $expectedSource -and
       $acceptance.candidate_sha256 -ceq $expectedZip -and
       $acceptance.exact_windows_zip_tested -eq $true -and
       $acceptance.ci_cross_platform_zip_repacked -eq $false -and
       $acceptance.automated_result -eq 'AUTOMATED_PASS_PENDING_MIGRATION_037_REVIEW' -and
       $acceptance.known_037_gap -eq $true -and
       $acceptance.actual_production_database_accessed -eq $false -and
       $acceptance.full_suite.playwright.passed -eq 12 -and
       $acceptance.candidate_suite.playwright.passed -eq 12 -and
       $acceptance.schema_suite.playwright.passed -eq 12 -and
       @($acceptance.gates|Where-Object{$_.status -eq 'FAIL'}).Count -eq 0
    )
    if(!$acceptanceOk){$issues.Add('Windows exact candidate, source, 36/36 technical or known-037 evidence mismatch.') | Out-Null}
}
if($null -eq $hist){
    $issues.Add('Offline private historical migration and independent restore summary is missing.') | Out-Null
}else{
    $historicalOk=(
        $hist.format -eq 'NONIUS_PRIVATE_HISTORICAL_REHEARSAL_V1' -and
        $hist.status -eq 'PASS' -and
        $hist.tested_source_commit -ceq $expectedSource -and
        $hist.original_tables -eq 21 -and
        $hist.original_columns -eq 177 -and
        $hist.original_triggers -eq 14 -and
        $hist.trigger_count_after_clone_upgrade -eq 15 -and
        $hist.migration037_initially -eq 'NO_ROW' -and
        $hist.migration037_in_isolated_clone -eq 'applied' -and
        $hist.restored_original_schema_and_data_digest_match -eq $true -and
        $hist.all_business_table_content_digests_unchanged -eq $true -and
        $hist.original_database_values_exported_in_report -eq $false -and
        $hist.real_data_sanitized -eq $false -and
        $hist.web_staging_uat_performed -eq $false -and
        $hist.production_go -eq $false -and
        $hist.production_approval -eq 'PENDING'
    )
    if(!$historicalOk){$issues.Add('Offline historical DB migration/restore did not meet the required no-data-loss checks.') | Out-Null}
}
$approvedGates=0
$approvalOk=$false
if($null -eq $approvals){
    $issues.Add('Signed private approval record is not present (template defaults to pending).') | Out-Null
}else{
    if($approvals.format -ne 'NONIUSWTAX_PRODUCTION_APPROVAL_V1' -or
       $approvals.verified_source_commit -cne $expectedSource -or
       $approvals.staging_zip_sha256 -cne $expectedZip -or
       $approvals.release_candidate_version -cne '0.4.57-rc.1'){
        $issues.Add('Private approval record does not identify the exact verified source and release candidate.') | Out-Null
    }else{
        foreach($key in $gateNames){
            $field=$approvals.gates.PSObject.Properties[$key]
            if($null -eq $field){
                $issues.Add(('Missing mandatory signoff gate: '+$key)) | Out-Null
                continue
            }
            $g=$field.Value
            if($g.status -eq 'APPROVED' -and (HasValue $g.reviewer) -and
               (HasValue $g.evidence_id) -and (IsISODate $g.reviewed_at)){
                $approvedGates++
            }else{$issues.Add(('Unsigned or incomplete approval: '+$key)) | Out-Null}
        }
        if($approvals.production_artifact_version -ne '0.4.57' -or
           $approvals.production_artifact_sha256 -notmatch '^[a-f0-9]{64}$' -or
           $approvals.production_artifact_sha256 -ceq $expectedZip -or
           !(HasValue $approvals.production_artifact_attestation_id)){
            $issues.Add('Final independently attested GA artifact v0.4.57 and exact staging rehearsal not established.') | Out-Null
        }
        $auth=$approvals.final_authorized_decision
        if($auth.decision -ne 'GO' -or
           !(HasValue $auth.authorizing_officer) -or
           !(HasValue $auth.approval_evidence_id) -or
           !(IsISODate $auth.signed_at)){
            $issues.Add('Formal authorized GO decision and signature reference are missing.') | Out-Null
        }
    }
}
$approvalOk=($null -ne $approvals -and $issues.Count -eq 0 -and $approvedGates -eq $gateNames.Count)
$decision=if($acceptanceOk -and $historicalOk -and $approvalOk){
    'GO_RECORDED_REQUIRES_INDEPENDENT_SIGNATURE_VERIFICATION'
}else{'NO_GO_BLOCKED_BY_OUTSTANDING_GATES'}
$outDir=Join-Path $root 'private-release-evidence'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$outputFile=Join-Path $outDir 'final-gate-report.json'
$summary=[ordered]@{
   format='NONIUSWTAX_FINAL_GATE_REVIEW_V1'
   generated_at_utc=(Get-Date).ToUniversalTime().ToString('o')
   decision=$decision
   executable_deployment_performed=$false
   source_commit=$expectedSource
   tested_staging_zip_sha256=$expectedZip
   exact_windows_technical_acceptance=$acceptanceOk
   private_closed_box_migration_and_restore=$historicalOk
   approved_manual_gates=$approvedGates
   total_required_manual_gates=$gateNames.Count
   out_of_band_signatures_cryptographically_verified=$false
   unapproved_or_unverified_gates=@($issues.ToArray())
   note='GO_RECORDED only means the private record contains completed signoff fields; independently verify actual signed evidence before executing the separate authorized production runbook. This script never deploys.'
}
[IO.File]::WriteAllText($outputFile,(($summary|ConvertTo-Json -Depth 10)+[Environment]::NewLine),(New-Object System.Text.UTF8Encoding($false)))
Write-Host ('FINAL PRODUCTION-READINESS RECORD: '+$decision) -ForegroundColor $(if($approvalOk){'Yellow'}else{'Red'})
Write-Host ('Approval gates recorded: '+$approvedGates+'/'+$gateNames.Count)
foreach($problem in $issues){Write-Host ('PENDING: '+$problem)}
Write-Host ('Report: '+$outputFile)
Write-Host 'No deployment took place. Signatures must be independently checked outside this script.'
if($decision -like 'GO_RECORDED*'){exit 0}
exit 2
