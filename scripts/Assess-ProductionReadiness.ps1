# NONIUSWTAX local, read-only production readiness evidence ASSESSOR.
# Does not connect to Hostinger, sign approvals, merge a PR or deploy anything.
# Only accepts reference metadata (never confidential source records).
param(
    [string]$EvidencePath = '.\docs\RELEASE_GATE_TEMPLATE.json',
    [string]$OutputDirectory = '.\readiness-results'
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$utf8=New-Object System.Text.UTF8Encoding($false)
$required=[ordered]@{
    R0='database_release_administrator'
    R1='database_recovery_reviewer'
    R2='privacy_information_owner'
    R3='database_release_administrator'
    R4='database_recovery_reviewer'
    R5='finance_tax_reviewer'
    R6='security_reviewer'
    R7='performance_uat_reviewer'
    R8='release_change_authority'
}
$sourceSha='adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
$candidateSha='e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a'
$issues=New-Object 'System.Collections.Generic.List[string]'
$gateRows=New-Object 'System.Collections.Generic.List[object]'
function Flag([string]$message){$script:issues.Add($message) | Out-Null}
function IsNonPlaceholder($value) {
    if($null -eq $value){return $false}
    $s=([string]$value).Trim()
    return $s.Length -ge 4 -and $s -notmatch '(?i)^(pending|todo|n/a|none|test|synthetic|placeholder|tbd|___+)$'
}
function IsSha($value){return $null -ne $value -and [string]$value -cmatch '^[0-9a-f]{64}$'}
function ValidTime($value) {
    if($null -eq $value){return $false}
    try {
        if($value -is [datetime]){
            $parsed=[datetimeoffset]::new($value.ToUniversalTime())
        } elseif($value -is [datetimeoffset]){
            $parsed=$value.ToUniversalTime()
        } else {
            $str=[string]$value
            if($str -notmatch '^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$'){return $false}
            $parsed=[datetimeoffset]::Parse($str,[System.Globalization.CultureInfo]::InvariantCulture)
        }
        $age=([datetimeoffset]::UtcNow - $parsed).TotalDays
        return ($age -ge -0.04) -and ($age -le 90)
    } catch {return $false}
}
function Html([string]$value){return [System.Net.WebUtility]::HtmlEncode($value)}

try {
    $inputPath=(Resolve-Path -LiteralPath $EvidencePath).Path
    if([System.IO.Path]::GetExtension($inputPath).ToLowerInvariant() -ne '.json' -or
       (Get-Item -LiteralPath $inputPath).Length -gt 1048576) {
        throw 'Provide a small metadata-only .json evidence file, not a raw backup or log.'
    }
    $raw=Get-Content -LiteralPath $inputPath -Raw
    $data=$raw | ConvertFrom-Json
    if($data.schema_version -ne 'NONIUSWTAX_PRODUCTION_GATE_EVIDENCE_V1' -or
       $data.confidentiality -ne 'REFERENCES_ONLY_NO_REAL_RECORDS_OR_SECRETS' -or
       $data.project -ne 'NONIUSWTAX') {
        throw 'Unrecognized readiness evidence contract. Expected docs/RELEASE_GATE_TEMPLATE.json format.'
    }
    if($data.synthetic_evidence -ne $false){Flag 'Synthetic or demo evidence cannot authorize formal review.'}
    if($data.release.source_commit -cne $sourceSha){Flag 'Source commit no longer matches the tested/reviewed baseline.'}
    if($data.release.tested_candidate_sha256 -cne $candidateSha){Flag 'Candidate ZIP checksum no longer matches the verified original.'}
    if($data.verified_synthetic_baseline.approval_conferred -ne $false){
        Flag 'Automated baseline may not mark approval_conferred=true.'
    }
    $version=$data.release.production_artifact_version
    $finalSha=$data.release.production_artifact_sha256
    if($version -cne '0.4.57'){
        Flag 'A fully versioned final 0.4.57 production artifact, separate from the staging-only RC, has not been verified.'
    }
    if(!(IsSha $finalSha)){Flag 'Missing or invalid final production artifact SHA-256.'}
    if(!(IsSha $data.release.last_artifact_retest_sha256) -or
       $data.release.last_artifact_retest_sha256 -cne $finalSha){
        Flag 'Final artifact has no matching completed staging re-test digest.'
    }
    if(!(IsNonPlaceholder $data.release.owner_authorized_change_id)){
        Flag 'No valid change-control reference was recorded.'
    }
    if(@($data.gates).Count -ne $required.Count){
        Flag 'Mandatory evidence gate count is incorrect or incomplete.'
    }
    $ids=@{}
    foreach($gate in @($data.gates)){
        $id=([string]$gate.id).Trim()
        if($ids.ContainsKey($id)){Flag ('Duplicate gate ID: '+$id);continue}
        $ids[$id]=$gate
        if(!$required.Contains($id)){Flag ('Unknown/unreviewed gate ID: '+$id)}
    }
    foreach($id in @($required.Keys)){
        $gate=$ids[$id]
        if($null -eq $gate){
            $gateRows.Add([pscustomobject]@{id=$id;status='MISSING';review='NOT_RECORDED'}) | Out-Null
            Flag ('Missing mandatory gate '+$id)
            continue
        }
        $status=([string]$gate.status).Trim()
        if($status -notin @('PASS','PENDING','FAIL')){
            Flag ($id+' has unsupported decision state (only PASS/PENDING/FAIL allowed).')
        }
        $problems=New-Object 'System.Collections.Generic.List[string]'
        if($status -ne 'PASS'){$problems.Add('Status is '+$status) | Out-Null}
        if($gate.reviewer_role -cne $required[$id]){
            $problems.Add('Role mismatch') | Out-Null
        }
        if(!(IsNonPlaceholder $gate.evidence_id)){
            $problems.Add('Missing evidence reference') | Out-Null
        }
        if(!(IsNonPlaceholder $gate.signoff_record_id)){
            $problems.Add('No signed reviewer record reference') | Out-Null
        }
        if(!(ValidTime $gate.reviewed_at_utc)){
            $problems.Add('No recent UTC review timestamp (within 90 days)') | Out-Null
        }
        if(!(IsSha $finalSha) -or $gate.artifact_sha256 -cne $finalSha){
            $problems.Add('Not explicitly signed against final artifact SHA-256') | Out-Null
        }
        if($gate.scope -cne 'STAGING_OR_READ_ONLY_ONLY'){
            $problems.Add('Unexpected/unsafe gate scope') | Out-Null
        }
        $outcome=if($problems.Count -eq 0){'EVIDENCE_COMPLETE'}else{'BLOCKED'}
        if($problems.Count -gt 0){
            Flag ($id+': '+($problems.ToArray() -join '; '))
        }
        $gateRows.Add([pscustomobject]@{
            id=$id
            name=[string]$gate.name
            status=$status
            reviewer_role=[string]$gate.reviewer_role
            result=$outcome
            reasons=@($problems.ToArray())
        }) | Out-Null
    }
    $decision=if($issues.Count -eq 0){'READY_FOR_FORMAL_GO_DECISION'}else{'NOT_READY'}
    # A self-asserted metadata file does NOT cryptographically validate a
    # handwritten/document signature. Never set a production GO field.
    $report=[ordered]@{
        generated_at_utc=(Get-Date).ToUniversalTime().ToString('o')
        report_type='NONIUS_PRODUCTION_READINESS_ASSESSMENT_V1'
        automated_assessment=$decision
        production_release='NO_GO_UNTIL_EXPLICIT_AUTHORIZED_CHANGE'
        deployment_authorized_by_this_script=$false
        reviewer_signatures_cryptographically_verified=$false
        changes_made_to_host_or_database=$false
        tested_source_sha256_reference=$sourceSha
        exact_candidate_sha256_reference=$candidateSha
        final_artifact_sha256=$finalSha
        candidate_version=[string]$data.release.tested_candidate_version
        final_artifact_version=$version
        mandatory_gates=$gateRows.ToArray()
        blockers=$issues.ToArray()
        caution='Private reviewer references are self-declared metadata; independently inspect signed approvals and verify final artifact before an actual GO meeting.'
    }
    $dir=if([System.IO.Path]::IsPathRooted($OutputDirectory)){$OutputDirectory}else{Join-Path $root $OutputDirectory}
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $reportPath=Join-Path $dir 'release-readiness-assessment.json'
    $htmlPath=Join-Path $dir 'release-readiness-assessment.html'
    [System.IO.File]::WriteAllText($reportPath,($report|ConvertTo-Json -Depth 12)+[Environment]::NewLine,$utf8)
    $rows=($gateRows.ToArray()|ForEach-Object{
        '<tr><td>'+(Html $_.id)+'</td><td>'+(Html $_.name)+'</td><td>'+(Html $_.status)+'</td><td>'+(Html $_.result)+'</td></tr>'
    }) -join [Environment]::NewLine
    $blockers=($issues.ToArray()|ForEach-Object{'<li>'+(Html $_)+'</li>'}) -join [Environment]::NewLine
    $html=@" 
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>NONIUSWTAX Production Readiness</title>
<style>body{font:16px/1.6 system-ui,Arial,sans-serif;max-width:960px;margin:40px auto;padding:0 24px;color:#182432}table{width:100%;border-collapse:collapse}td,th{padding:10px;border-bottom:1px solid #d6dde5;text-align:left}th{background:#f4f6f9}.alert{padding:16px;border:1px solid #c7ced7;background:#f5f6f8;border-radius:8px}h1{font-size:26px}h2{font-size:19px}li{margin-bottom:6px}</style></head><body>
<h1>NONIUSWTAX — Release Readiness Assessment</h1><div class="alert">
<strong>Evidence status: $(Html $decision)</strong>
<p><strong>Production: NO-GO until independent approval and explicit authorized deployment instructions.</strong></p>
<p>Machine check does NOT independently authenticate reviewer signatures.</p></div>
<h2>Mandatory gates</h2><table><thead><tr><th>ID</th><th>Gate</th><th>Declared</th><th>Assessment</th></tr></thead><tbody>$rows</tbody></table>
<h2>Unresolved blockers</h2><ul>$blockers</ul>
<p>Private evidence references only. No Hostinger connection, file upload, database operation or deployment was made.</p></body></html>
"@
    [System.IO.File]::WriteAllText($htmlPath,$html,$utf8)
    Write-Host ('Readiness: '+$decision) -ForegroundColor $(if($issues.Count -eq 0){'Green'}else{'Yellow'})
    Write-Host ('Report: '+$reportPath)
    Write-Host ('HTML: '+$htmlPath)
    Write-Host 'This tool NEVER authorizes deployment.'
    if($issues.Count -gt 0){exit 2}
    exit 0
} catch {
    Write-Error ('Readiness assessment rejected: '+$_.Exception.Message)
    exit 1
}
