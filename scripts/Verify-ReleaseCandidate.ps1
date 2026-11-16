# NONIUSWTAX release-candidate verifier; Windows PowerShell 5.1 compatible.
# This verifies integrity only. It does not authorize uploading or installing.
param(
    [Parameter(Mandatory=$true)][string]$PackagePath,
    [string]$ExpectedSourceCommit = 'adf3fb4bdce027f65c2a18de2ca4d94e8424e35f',
    [string]$ExpectedVersion = '0.4.57-rc.1'
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$utf8 = New-Object System.Text.UTF8Encoding($false)
$path = (Resolve-Path -LiteralPath $PackagePath).Path
$shaPath = $path + '.sha256'
if (!(Test-Path -LiteralPath $shaPath)) { throw 'Missing independent ZIP checksum sidecar (.sha256).' }
$actualZipHash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
$expectedZipHash = ((Get-Content -LiteralPath $shaPath -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
if ($actualZipHash -cne $expectedZipHash) { throw 'Candidate ZIP SHA-256 does not match sidecar.' }
$sha = [System.Security.Cryptography.SHA256]::Create()
function Digest([byte[]]$data) {
    return [BitConverter]::ToString($sha.ComputeHash($data)).Replace('-', '').ToLowerInvariant()
}
function ReadZipBytes($entry) {
    $stream = $entry.Open()
    $memory = New-Object System.IO.MemoryStream
    try { $stream.CopyTo($memory); return ,$memory.ToArray() }
    finally { $memory.Dispose(); $stream.Dispose() }
}
$zip = [System.IO.Compression.ZipFile]::OpenRead($path)
try {
    $contents = @{}
    foreach ($item in $zip.Entries) {
        if ([string]::IsNullOrEmpty($item.Name)) { continue }
        $name = $item.FullName
        if ($name.Contains('\\') -or $name.StartsWith('/') -or $name -match '(^|/)\.\.?(/|$)') {
            throw "Unsafe ZIP path: $name"
        }
        if ($contents.ContainsKey($name)) { throw "Duplicate ZIP entry: $name" }
        $contents[$name] = ReadZipBytes $item
    }
    if (!$contents.ContainsKey('nonius-update.json')) { throw 'No in-app updater manifest in candidate ZIP.' }
    $manifest = $utf8.GetString($contents['nonius-update.json']) | ConvertFrom-Json
    if ($manifest.product -cne 'Non-IUS Withholding Tax Monitoring') { throw 'Unexpected product in manifest.' }
    if ($manifest.version -cne $ExpectedVersion) { throw 'Unexpected release version.' }
    if ($manifest.source_commit -cne $ExpectedSourceCommit) { throw 'Unexpected source commit; release source is not the verified baseline.' }
    if ($manifest.release_channel -cne 'unapproved-release-candidate') { throw 'Approval status must remain candidate.' }
    if ($manifest.minimum_version -cne '0.4.56') { throw 'Unexpected minimum installed app version.' }
    if ($manifest.minimum_php -cne '8.2.0') { throw 'Unexpected minimum PHP.' }
    $fileNames = @($manifest.files.PSObject.Properties.Name)
    $actualPaths = @($contents.Keys | Where-Object { $_ -cne 'nonius-update.json' })
    if (@(Compare-Object $fileNames $actualPaths).Length -ne 0) { throw 'Payload inventory differs from manifest file list.' }
    foreach ($name in $fileNames) {
        if ($name -match '(?i)(?:^|/)(?:storage|tests|docs|vendor|node_modules)/' -or
            $name -match '(?i)(?:^|/)(?:config\.local\.php|\.env|.*\.sql|.*\.pem|.*\.key|.*\.zip|.*\.bak)$') {
            throw "Forbidden payload entry: $name"
        }
        $expected = [string]$manifest.files.PSObject.Properties[$name].Value
        if ((Digest $contents[$name]) -cne $expected.ToLowerInvariant()) { throw "Payload hash mismatch: $name" }
    }
    foreach ($needed in @('lib/bootstrap.php','CHANGELOG.txt','api.php','update.php','lib/migrations.php','.htaccess','assets/bir2307-v1.pdf')) {
        if (!$contents.ContainsKey($needed)) { throw "Critical payload file missing: $needed" }
    }
    $bootstrap = $utf8.GetString($contents['lib/bootstrap.php'])
    if (!$bootstrap.Contains("const APP_VERSION = '$ExpectedVersion';")) { throw 'Runtime version and package version differ.' }
    $changelog = $utf8.GetString($contents['CHANGELOG.txt'])
    if (!$changelog.StartsWith("Non-IUS Withholding Tax Monitoring v$ExpectedVersion")) { throw 'Candidate changelog header mismatch.' }
    if ($contents.Count -lt 22) { throw 'Suspiciously incomplete release ZIP.' }
    Write-Host ('PASS: candidate ZIP hash and ' + $fileNames.Count + ' per-file SHA-256 hashes verified.') -ForegroundColor Green
    Write-Host ('PASS: pinned source ' + $ExpectedSourceCommit + ', version ' + $ExpectedVersion + ', no private files.')
    Write-Host 'NOT AUTHORIZED FOR PRODUCTION. Independent finance, security, upgrade and owner sign-off required.' -ForegroundColor Yellow
}
finally { $zip.Dispose(); $sha.Dispose() }
