#Requires -Version 5.1
<#
.SYNOPSIS
  PowerShell-safe helpers for the Ereview examination load-test harness.

.DESCRIPTION
  Avoids PowerShell parsing pitfalls such as $db: being treated as a drive-
  qualified variable. Prefer ${env:VAR} / ${VAR} when a value is adjacent to ':'.

  Does NOT run against production. Requires EREVIEW_LOADTEST flags via
  config.local.env or environment variables.

.EXAMPLE
  .\scripts\loadtest\Invoke-Loadtest.ps1 -Action SelfCheck

.EXAMPLE
  .\scripts\loadtest\Invoke-Loadtest.ps1 -Action Seed -N 5
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet(
        'SelfCheck',
        'NegativeSafety',
        'HttpPreflight',
        'Seed',
        'Bootstrap',
        'StartAttempts',
        'Align',
        'BuildK6Input',
        'MysqlBefore',
        'MysqlAfter',
        'Monitor',
        'ParseK6Stdout',
        'Verify',
        'Teardown',
        'ShowEnv'
    )]
    [string]$Action,

    [int]$N = 5,

    [string]$RunId = '',

    [string]$K6StdoutPath = '',

    [string]$PhpExe = 'C:\xampp\php\php.exe',

    [string]$ConfigFile = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$HarnessDir = $PSScriptRoot
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $HarnessDir)

function Import-LoadtestEnvFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return }
    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { return }
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim()
        if ($val.StartsWith('"') -and $val.EndsWith('"')) {
            $val = $val.Substring(1, $val.Length - 2)
        }
        elseif ($val.StartsWith("'") -and $val.EndsWith("'")) {
            $val = $val.Substring(1, $val.Length - 2)
        }
        # Do not override variables already set in the process
        $existing = [Environment]::GetEnvironmentVariable($key, 'Process')
        if ([string]::IsNullOrEmpty($existing)) {
            Set-Item -Path ("Env:{0}" -f $key) -Value $val
        }
    }
}

function Ensure-LoadtestSafetyFlags {
    # Fail closed — never invent safety flags.
    $flag = "${env:EREVIEW_LOADTEST}".Trim().ToUpperInvariant()
    $confirm = "${env:EREVIEW_LOADTEST_CONFIRM}".Trim().ToUpperInvariant()
    if ($flag -ne '1' -and $flag -ne 'TRUE' -and $flag -ne 'YES') {
        throw 'EREVIEW_LOADTEST=1 is required. Refusing to continue.'
    }
    if ($confirm -ne 'YES') {
        throw 'EREVIEW_LOADTEST_CONFIRM=YES is required. Refusing to continue.'
    }
    if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_DB_NAME}")) {
        throw 'LOADTEST_DB_NAME is required (allowlist: ereview_loadtest, ereview_test). Refusing to guess.'
    }
    $dbName = "${env:LOADTEST_DB_NAME}".Trim().ToLowerInvariant()
    $allowed = @('ereview_loadtest', 'ereview_test')
    $blocked = @('ereview', 'ereview_prod', 'ereview_production', 'production', 'prod')
    if ($blocked -contains $dbName) {
        throw "LOADTEST_DB_NAME='$dbName' is blocked (production). Refusing to continue."
    }
    if ($allowed -notcontains $dbName) {
        throw "LOADTEST_DB_NAME='$dbName' is not allowlisted. Allowed: $($allowed -join ', ')"
    }
}

function Invoke-LoadtestPhp {
    param(
        [Parameter(Mandatory = $true)][string]$ScriptRelative,
        [string[]]$ExtraArgs = @()
    )
    if (-not (Test-Path -LiteralPath $PhpExe)) {
        throw "PHP executable not found: $PhpExe"
    }
    $scriptPath = Join-Path $HarnessDir $ScriptRelative
    if (-not (Test-Path -LiteralPath $scriptPath)) {
        throw "Harness script not found: $scriptPath"
    }
    Push-Location $ProjectRoot
    try {
        & $PhpExe $scriptPath @ExtraArgs
        if ($LASTEXITCODE -ne 0) {
            throw "PHP script failed ($LASTEXITCODE): $ScriptRelative"
        }
    }
    finally {
        Pop-Location
    }
}

# Load ONLY local/explicit config — never auto-apply config.example.env
# (example is a template; loading it would silently invent safety flags).
$local = if ($ConfigFile) { $ConfigFile } else { Join-Path $HarnessDir 'config.local.env' }
if ($ConfigFile -and -not (Test-Path -LiteralPath $ConfigFile)) {
    throw "ConfigFile not found: $ConfigFile"
}
Import-LoadtestEnvFile -Path $local

if ($N -gt 0) {
    Set-Item -Path Env:LOADTEST_N -Value ([string]$N)
}
if (-not [string]::IsNullOrWhiteSpace($RunId)) {
    Set-Item -Path Env:LOADTEST_RUN_ID -Value $RunId
}
elseif ([string]::IsNullOrWhiteSpace("${env:LOADTEST_RUN_ID}")) {
    $latest = Join-Path $HarnessDir 'artifacts\LATEST_RUN_ID'
    if (Test-Path -LiteralPath $latest) {
        $fromFile = (Get-Content -LiteralPath $latest -Raw).Trim()
        if (-not [string]::IsNullOrWhiteSpace($fromFile)) {
            Set-Item -Path Env:LOADTEST_RUN_ID -Value $fromFile
        }
    }
}

Write-Host "Action=$Action"
Write-Host ("LOADTEST_DB_NAME={0}" -f "${env:LOADTEST_DB_NAME}")
Write-Host ("LOADTEST_N={0}" -f "${env:LOADTEST_N}")
Write-Host ("LOADTEST_RUN_ID={0}" -f "${env:LOADTEST_RUN_ID}")
Write-Host ("LOADTEST_BASE_URL={0}" -f "${env:LOADTEST_BASE_URL}")

switch ($Action) {
    'SelfCheck' {
        Invoke-LoadtestPhp -ScriptRelative 'harness_selfcheck.php'
        Invoke-LoadtestPhp -ScriptRelative 'negative_safety_tests.php'
    }
    'NegativeSafety' {
        Invoke-LoadtestPhp -ScriptRelative 'negative_safety_tests.php'
    }
    'HttpPreflight' {
        Ensure-LoadtestSafetyFlags
        if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
            throw 'LOADTEST_BASE_URL is required for HttpPreflight (no default).'
        }
        Invoke-LoadtestPhp -ScriptRelative '09_http_preflight.php'
    }
    'ShowEnv' {
        Ensure-LoadtestSafetyFlags
        Write-Host 'Safety flags OK (no DB connection attempted).'
        Write-Host 'HTTP/k6 default: BLOCKED until HttpPreflight writes status=SAFE in http_attestation.json.'
    }
    'Seed' {
        Ensure-LoadtestSafetyFlags
        if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
            throw 'LOADTEST_BASE_URL is required for Seed (no default).'
        }
        Invoke-LoadtestPhp -ScriptRelative '01_seed.php'
    }
    'Bootstrap' {
        Ensure-LoadtestSafetyFlags
        Invoke-LoadtestPhp -ScriptRelative '02_bootstrap_sessions.php'
    }
    'StartAttempts' {
        Ensure-LoadtestSafetyFlags
        if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
            throw 'LOADTEST_BASE_URL is required for StartAttempts (no default).'
        }
        Invoke-LoadtestPhp -ScriptRelative '03_start_attempts.php'
    }
    'Align' {
        Ensure-LoadtestSafetyFlags
        Invoke-LoadtestPhp -ScriptRelative '04_align_expires.php'
    }
    'BuildK6Input' {
        Ensure-LoadtestSafetyFlags
        if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
            throw 'LOADTEST_BASE_URL is required for BuildK6Input (no default).'
        }
        Invoke-LoadtestPhp -ScriptRelative 'build_k6_input.php'
    }
    'MysqlBefore' {
        Ensure-LoadtestSafetyFlags
        Set-Item -Path Env:LOADTEST_METRIC_PHASE -Value 'before'
        Invoke-LoadtestPhp -ScriptRelative '07_collect_mysql_status.php'
    }
    'MysqlAfter' {
        Ensure-LoadtestSafetyFlags
        Set-Item -Path Env:LOADTEST_METRIC_PHASE -Value 'after'
        Invoke-LoadtestPhp -ScriptRelative '07_collect_mysql_status.php'
    }
    'Monitor' {
        Ensure-LoadtestSafetyFlags
        if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
            throw 'LOADTEST_BASE_URL is required for Monitor (no default).'
        }
        Invoke-LoadtestPhp -ScriptRelative '05_monitor_poll.php'
    }
    'ParseK6Stdout' {
        Ensure-LoadtestSafetyFlags
        if ([string]::IsNullOrWhiteSpace($K6StdoutPath)) {
            $rid = "${env:LOADTEST_RUN_ID}"
            $K6StdoutPath = Join-Path $HarnessDir ("artifacts\{0}\k6_stdout.txt" -f $rid)
        }
        Invoke-LoadtestPhp -ScriptRelative 'parse_k6_stdout.php' -ExtraArgs @($K6StdoutPath)
    }
    'Verify' {
        Ensure-LoadtestSafetyFlags
        Invoke-LoadtestPhp -ScriptRelative '06_verify_integrity.php'
    }
    'Teardown' {
        Ensure-LoadtestSafetyFlags
        if ("${env:LOADTEST_TEARDOWN_CONFIRM}" -ne 'YES') {
            throw 'Set LOADTEST_TEARDOWN_CONFIRM=YES before Teardown.'
        }
        Invoke-LoadtestPhp -ScriptRelative '08_teardown.php'
    }
}

Write-Host "Done: $Action"
