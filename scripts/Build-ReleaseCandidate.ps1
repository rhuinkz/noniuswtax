# Build a STAGING-ONLY updater-compatible NONIUSWTAX release candidate.
# Windows PowerShell 5.1 + Git required. Never sends data to hosting.
param([string]$OutputDirectory = 'release-out')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$VerifiedSource = 'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f'
$CurrentVersion = '0.4.56'
$CandidateVersion = '0.4.57-rc.1'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$utf8 = New-Object System.Text.UTF8Encoding($false)
$sha = [System.Security.Cryptography.SHA256]::Create()
function Sha256Bytes([byte[]]$bytes) {
    return [BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
}
function ReadZipBytes($entry) {
    $sourceStream = $entry.Open()
    $buffer = New-Object System.IO.MemoryStream
    try { $sourceStream.CopyTo($buffer); return ,$buffer.ToArray() }
    finally { $buffer.Dispose(); $sourceStream.Dispose() }
}
function PayloadAllowed([string]$name) {
    if ($name -cmatch '^(api|first-login|forgot-password|index|install|login-images|login|logo|logout|sql-console|system|update)\.php$') { return $true }
    if ($name -ceq 'CHANGELOG.txt' -or $name -ceq '.htaccess') { return $true }
    if ($name -cmatch '^lib/[a-z0-9_-]+\.php$') { return $true }
    if ($name -cmatch '^bin/sync-login-images\.php$') { return $true }
    if ($name -cmatch '^assets/(app\.js|style\.css|login-slideshow\.js|bir2307-v1\.pdf)$') { return $true }
    return $false
}
Push-Location $Root
$tmpFolder = Join-Path ([System.IO.Path]::GetTempPath()) ('nonius-rc-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tmpFolder -Force | Out-Null
try {
    $actual = (& git rev-parse $VerifiedSource).Trim()
    if ($LASTEXITCODE -ne 0 -or $actual -cne $VerifiedSource) { throw 'Verified baseline commit not available locally. Fetch origin first.' }
    $gitArchive = Join-Path $tmpFolder 'pinned-source.zip'
    & git archive '--format=zip' ('--output=' + $gitArchive) $VerifiedSource
    if ($LASTEXITCODE -ne 0 -or !(Test-Path $gitArchive)) { throw 'Git could not archive the pinned verified commit.' }
    $zip = [System.IO.Compression.ZipFile]::OpenRead($gitArchive)
    $payload = @{}
    try {
        foreach ($entry in $zip.Entries) {
            if ([string]::IsNullOrEmpty($entry.Name)) { continue }
            $name = [string]$entry.FullName
            if (-not (PayloadAllowed $name)) { continue }
            if ($payload.ContainsKey($name)) { throw "Duplicate source payload path: $name" }
            $payload[$name] = ReadZipBytes $entry
        }
    }
    finally { $zip.Dispose() }
    foreach ($needed in @('api.php','lib/bootstrap.php','lib/migrations.php','update.php','.htaccess','CHANGELOG.txt','assets/bir2307-v1.pdf')) {
        if (!$payload.ContainsKey($needed)) { throw "Pinned source is missing a required runtime file: $needed" }
    }
    $old = "const APP_VERSION = '$CurrentVersion';"
    $new = "const APP_VERSION = '$CandidateVersion';"
    $bootstrap = $utf8.GetString($payload['lib/bootstrap.php'])
    if (!$bootstrap.Contains($old)) { throw 'Expected v0.4.56 bootstrap version not found. Refusing to build from changed source.' }
    $payload['lib/bootstrap.php'] = $utf8.GetBytes($bootstrap.Replace($old, $new))
    $notes = "Non-IUS Withholding Tax Monitoring v$CandidateVersion" + "`n" +
        "- RELEASE CANDIDATE ONLY. NOT APPROVED FOR PRODUCTION INSTALLATION.`n" +
        "- Source baseline $VerifiedSource, tested 2026-11-16 using synthetic local Docker data.`n" +
        "- Review-candidate security fixes: remove destructive reset action, read-only SQL diagnostics and closed-period certificate seal guard.`n" +
        "- No approved changes to VAT/EWT arithmetic; independent BIR rule and printed Form 2307 approval pending.`n" +
        "- Existing 0.4.56 installations require isolated upgrade, restore and rollback testing before production.`n" +
        "- Changes to the version constant and this changelog were introduced for this candidate; candidate updater flow not yet accepted.`n`n"
    $payload['CHANGELOG.txt'] = $utf8.GetBytes($notes + $utf8.GetString($payload['CHANGELOG.txt']))
    $hashes = [ordered]@{}
    $paths = @($payload.Keys | Sort-Object -CaseSensitive)
    foreach ($name in $paths) { $hashes[$name] = Sha256Bytes $payload[$name] }
    $manifest = [ordered]@{
        product = 'Non-IUS Withholding Tax Monitoring'
        version = $CandidateVersion
        minimum_version = $CurrentVersion
        minimum_php = '8.2.0'
        description = 'UNAPPROVED release candidate for isolated staging review only. Never install in production until authorization.'
        release_channel = 'unapproved-release-candidate'
        source_commit = $VerifiedSource
        files = $hashes
    }
    $payload['nonius-update.json'] = $utf8.GetBytes(($manifest | ConvertTo-Json -Depth 12) + "`n")
    $dest = if ([System.IO.Path]::IsPathRooted($OutputDirectory)) { $OutputDirectory } else { Join-Path $Root $OutputDirectory }
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
    $zipName = 'noniuswtax-' + $CandidateVersion + '-STAGING-ONLY-' + $VerifiedSource.Substring(0,7) + '.zip'
    $finalZip = Join-Path $dest $zipName
    if (Test-Path $finalZip) { throw "Candidate ZIP already exists: $finalZip. Archive it and choose another output folder for a fresh build." }
    $out = [System.IO.Compression.ZipFile]::Open($finalZip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($name in @($payload.Keys | Sort-Object -CaseSensitive)) {
            $item = $out.CreateEntry($name, [System.IO.Compression.CompressionLevel]::Optimal)
            # Fixed timestamp means byte-for-byte reproducibility from the pinned commit.
            $item.LastWriteTime = [DateTimeOffset]::Parse('2026-11-16T00:00:00Z')
            $stream = $item.Open()
            try { $stream.Write($payload[$name], 0, $payload[$name].Length) }
            finally { $stream.Dispose() }
        }
    }
    finally { $out.Dispose() }
    $fileHash = (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllText($finalZip + '.sha256', ($fileHash + '  ' + $zipName + "`n"), $utf8)
    $record = [ordered]@{
        candidate_version = $CandidateVersion
        status = 'NOT_APPROVED_FOR_PRODUCTION'
        source_commit = $VerifiedSource
        application_baseline_version = $CurrentVersion
        installer_type = 'in-app-updater-compatible-staging-candidate'
        archive_filename = $zipName
        archive_sha256 = $fileHash
        file_count = $hashes.Count
        independently_signed = $false
        approved_by_finance = $false
        approved_by_security = $false
        approved_staging_upgrade = $false
        approved_production_deployment = $false
    }
    [System.IO.File]::WriteAllText((Join-Path $dest 'release-provenance.json'), (($record | ConvertTo-Json -Depth 10) + "`n"), $utf8)
    & (Join-Path $PSScriptRoot 'Verify-ReleaseCandidate.ps1') -PackagePath $finalZip -ExpectedSourceCommit $VerifiedSource -ExpectedVersion $CandidateVersion
    if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) { throw 'Candidate checksum and manifest validation failed.' }
    Write-Host ('STAGING CANDIDATE CREATED: ' + $finalZip) -ForegroundColor Green
    Write-Host ('SHA-256: ' + $fileHash)
    Write-Host 'KEEP OFF PRODUCTION. Package must undergo staged updater/rollback and formal approval.' -ForegroundColor Yellow
}
finally {
    $sha.Dispose()
    Pop-Location
    if (Test-Path $tmpFolder) { Remove-Item -LiteralPath $tmpFolder -Recurse -Force }
}
