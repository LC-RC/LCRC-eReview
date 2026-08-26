#Requires -Version 5.1
<#
.SYNOPSIS
  Safely print the configured load-test DB name without PowerShell $db: parse errors.

.DESCRIPTION
  Reads LOADTEST_DB_NAME from the environment / config.local.env.
  Optionally probes db.local.php via a temporary PHP file (never inline $db: in PowerShell).
#>
[CmdletBinding()]
param(
    [string]$PhpExe = 'C:\xampp\php\php.exe',
    [switch]$ProbeDbLocalPhp
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$HarnessDir = $PSScriptRoot
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $HarnessDir)

function Import-EnvFile([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { return }
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim().Trim('"').Trim("'")
        $existing = [Environment]::GetEnvironmentVariable($key, 'Process')
        if ([string]::IsNullOrEmpty($existing)) {
            Set-Item -Path ("Env:{0}" -f $key) -Value $val
        }
    }
}

Import-EnvFile (Join-Path $HarnessDir 'config.local.env')

Write-Host ("Process LOADTEST_DB_NAME={0}" -f "${env:LOADTEST_DB_NAME}")

if ($ProbeDbLocalPhp) {
    $local = Join-Path $ProjectRoot 'db.local.php'
    if (-not (Test-Path -LiteralPath $local)) {
        Write-Host 'db.local.php not found'
        exit 0
    }
    if (-not (Test-Path -LiteralPath $PhpExe)) {
        throw "PHP not found: $PhpExe"
    }
    # IMPORTANT: do not embed $db: inside a PowerShell double-quoted string.
    # Use a temp PHP file so PowerShell never parses PHP variables.
    $tmp = Join-Path "${env:TEMP}" 'ereview_loadtest_read_db_name.php'
    $phpProbe = @'
<?php
require $argv[1];
$name = isset($db) ? (string)$db : '';
fwrite(STDOUT, $name === '' ? "unset\n" : ($name . "\n"));
'@
    # UTF-8 without BOM so PHP sees `<?php` as the first bytes.
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($tmp, $phpProbe, $utf8NoBom)
    try {
        $dbFromLocal = & $PhpExe $tmp $local
        # ${} so a following colon cannot attach to the variable name.
        Write-Host ("db.local.php db={0}" -f "${dbFromLocal}")
    }
    finally {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
    }
}
