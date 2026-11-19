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
        $LASTEXITCODE=-9999
        if($CaptureStdout){
            # The caller may consume only vetted aggregate DB metadata.
            # Native stderr is written exclusively to a private local log.
            $out=@(& $Program @Arguments 2> $PrivateLogPath)
        }else{
            # Import can output sensitive SQL details on either stream.
            # Keep BOTH streams in private log OUTSIDE the repository.
            & $Program @Arguments *>&1 | Out-File -LiteralPath $PrivateLogPath -Encoding UTF8
        }
        $nativeExit=[int]$LASTEXITCODE
    }finally{
        $ErrorActionPreference=$previousPreference
    }
    return [pscustomobject]@{
        ExitCode=$nativeExit
        Stdout=@($out)
    }
}
