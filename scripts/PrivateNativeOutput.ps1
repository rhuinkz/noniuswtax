# Only local native-process output handling for PRIVATE R1 recovery tools.
# Windows PowerShell 5.1 may turn nonfatal native stderr (Docker Compose's
# "Container ... Creating") into NativeCommandError under Stop preference.
# Judge success by the native process exit code, never presence of stderr.
# Do NOT print output/log contents: SQL failures can expose private literals.
function Invoke-PrivateNativeLogged {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory=$true)][string]$Program,
        [Parameter(Mandatory=$true)][string[]]$Arguments,
        [Parameter(Mandatory=$true)][string]$PrivateLogPath,
        [switch]$CaptureStdout
    )
    $previousPreference=$ErrorActionPreference
    $out=@()
    $nativeExit=-9999
    try {
        # Scoped to this helper; restore regardless of execution outcome.
        # Error records emitted from native stderr must not bypass LASTEXITCODE.
        $ErrorActionPreference='Continue'
        # Do not assign a local $LASTEXITCODE sentinel: on Windows PowerShell
        # 5.1 it can shadow the native process exit code and report a false failure.
        if($CaptureStdout){
            # Avoid native-command pipelines: in Windows PowerShell 5.1
            # LASTEXITCODE can be scoped to an inner pipeline, leaving
            # a stale exit value in the caller. Redirect direct to files,
            # capture the exit code immediately, then read only aggregate
            # stdout back into memory. Both files stay outside Git.
            $privateStdoutPath=$PrivateLogPath+'.stdout'
            try {
                & $Program @Arguments 1> $privateStdoutPath 2> $PrivateLogPath
                $nativeExit=[int]$LASTEXITCODE
                if(Test-Path -LiteralPath $privateStdoutPath){
                    $out=@(Get-Content -LiteralPath $privateStdoutPath)
                }
            }finally{
                if(Test-Path -LiteralPath $privateStdoutPath){
                    Remove-Item -LiteralPath $privateStdoutPath -Force
                }
            }
        }else{
            # Import can output sensitive SQL details on either stream.
            # Keep BOTH streams in private log OUTSIDE the repository.
            # No pipeline: retain the real docker.exe exit code.
            & $Program @Arguments 1> $PrivateLogPath 2>&1
            $nativeExit=[int]$LASTEXITCODE
        }
    }finally{
        $ErrorActionPreference=$previousPreference
    }
    return [pscustomobject]@{
        ExitCode=$nativeExit
        Stdout=@($out)
    }
}
