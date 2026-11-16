# Read-only, unauthenticated HTTPS security preflight for an explicitly selected
# NONIUSWTAX host. No login, POST, scanning of unrelated paths, or secrets.
param(
    [Parameter(Mandatory=$true)][string]$BaseUrl,
    [string]$OutputPath = 'release-out/hosting-preflight.json'
)
$ErrorActionPreference = 'Stop'
$uri = [uri]$BaseUrl
if ($uri.Scheme -cne 'https' -or [string]::IsNullOrWhiteSpace($uri.Host)) {
    throw 'Provide the authorized HTTPS base URL for the NONIUSWTAX deployment.'
}
$root = $uri.AbsoluteUri.TrimEnd('/')
if ($root -notmatch '^https://') { throw 'Only HTTPS URLs are permitted.' }
$deny = @(
    '/config.local.php',
    '/lib/bootstrap.php',
    '/storage/backups/do-not-exist.sql',
    '/bin/sync-login-images.php',
    '/.git/config',
    '/.github/workflows/lint.yml',
    '/.env',
    '/tests/release/README.md',
    '/docs/RELEASE_ACCEPTANCE_GATE.md',
    '/nonius-update.json',
    '/INSTALL_KEY.txt'
)
$checks = @()
foreach ($part in $deny) {
    $url = $root + $part
    $request = [System.Net.HttpWebRequest]::Create($url)
    $request.Method = 'GET'
    $request.AllowAutoRedirect = $false
    $request.Timeout = 10000
    $request.ReadWriteTimeout = 10000
    $request.UserAgent = 'NONIUSWTAX-owner-readonly-security-preflight'
    $response = $null
    try { $response = $request.GetResponse() }
    catch [System.Net.WebException] {
        if ($_.Exception.Response) { $response = $_.Exception.Response }
        else { $checks += [pscustomobject]@{ path=$part; status=0; result='UNVERIFIED'; note='No HTTP response' }; continue }
    }
    try {
        $code = [int]$response.StatusCode
        $blocked = @([int]403,[int]404,[int]410) -contains $code
        $checks += [pscustomobject]@{
            path=$part
            status=$code
            result=if($blocked){'PASS'}else{'FAIL'}
            note=if($blocked){'Private path denied'}else{'Unexpected status; check for exposed/redirected private resource'}
        }
    }
    finally { $response.Close() }
}
$allPass = (@($checks | Where-Object { $_.result -ne 'PASS' }).Count -eq 0)
$output = [ordered]@{
    performed_at_utc=(Get-Date).ToUniversalTime().ToString('o')
    authorized_host=$uri.Host
    read_only=$true
    private_path_denial=if($allPass){'PASS'}else{'FAIL_OR_UNVERIFIED'}
    production_security_approved=$false
    warning='This narrow unauthenticated HTTP check does not verify accounts, TLS policy, WAF, secrets, backup access, version, or tenant isolation.'
    checks=$checks
}
$dest = if ([System.IO.Path]::IsPathRooted($OutputPath)) { $OutputPath } else { Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..')).Path $OutputPath }
$folder = Split-Path -Parent $dest
New-Item -ItemType Directory -Path $folder -Force | Out-Null
[System.IO.File]::WriteAllText($dest, ($output | ConvertTo-Json -Depth 8), (New-Object System.Text.UTF8Encoding($false)))
foreach($row in $checks){ Write-Host ($row.result + ' HTTP ' + $row.status + ' ' + $row.path) }
Write-Host ('Saved read-only preflight: ' + $dest)
Write-Host 'IMPORTANT: Production security sign-off remains PENDING.'
if (!$allPass) { exit 1 }
