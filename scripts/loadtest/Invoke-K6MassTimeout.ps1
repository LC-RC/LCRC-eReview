#Requires -Version 5.1
<#
.SYNOPSIS
  Run k6 mass-timeout scenario only after SAFE http attestation.

.EXAMPLE
  .\scripts\loadtest\Invoke-K6MassTimeout.ps1 -N 5
#>
[CmdletBinding()]
param(
    [int]$N = 5,
    [string]$RunId = '',
    [double]$AutosaveFailureRate = 0.20,
    [double]$DuplicateSubmitRate = 0.15,
    [string]$K6Exe = 'k6'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$HarnessDir = $PSScriptRoot
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $HarnessDir)

$local = Join-Path $HarnessDir 'config.local.env'
if (Test-Path -LiteralPath $local) {
    Get-Content -LiteralPath $local | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { return }
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim().Trim('"').Trim("'")
        if ([string]::IsNullOrEmpty([Environment]::GetEnvironmentVariable($key, 'Process'))) {
            Set-Item -Path ("Env:{0}" -f $key) -Value $val
        }
    }
}

if (-not [string]::IsNullOrWhiteSpace($RunId)) {
    Set-Item -Path Env:LOADTEST_RUN_ID -Value $RunId
}
elseif ([string]::IsNullOrWhiteSpace("${env:LOADTEST_RUN_ID}")) {
    $latest = Join-Path $HarnessDir 'artifacts\LATEST_RUN_ID'
    if (Test-Path -LiteralPath $latest) {
        Set-Item -Path Env:LOADTEST_RUN_ID -Value ((Get-Content -LiteralPath $latest -Raw).Trim())
    }
}

if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_RUN_ID}")) {
    throw 'LOADTEST_RUN_ID is empty. Run seed/bootstrap/start/align first.'
}
if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
    throw 'LOADTEST_BASE_URL is required (no default). Set it explicitly before k6.'
}
if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_DB_NAME}")) {
    throw 'LOADTEST_DB_NAME is required before k6.'
}
$dbEnv = ("${env:LOADTEST_DB_NAME}").ToLowerInvariant()
$allowedDbs = @('ereview_loadtest', 'ereview_test')
if ($allowedDbs -notcontains $dbEnv) {
    throw ("LOADTEST_DB_NAME '{0}' is not allowlisted" -f $dbEnv)
}

$phpExe = if (Test-Path -LiteralPath 'C:\xampp\php\php.exe') { 'C:\xampp\php\php.exe' } else { 'php' }
Write-Host 'Running HTTP preflight (requires runtime SAFE attestation)...'
& $phpExe (Join-Path $HarnessDir '09_http_preflight.php')
if ($LASTEXITCODE -ne 0) {
    throw 'HttpPreflight did not return SAFE (exit non-zero). HTTP/k6 BLOCKED.'
}

$attestationPath = Join-Path $HarnessDir 'artifacts\http_attestation.json'
if (-not (Test-Path -LiteralPath $attestationPath)) {
    throw 'Missing artifacts/http_attestation.json after preflight'
}
$att = Get-Content -LiteralPath $attestationPath -Raw | ConvertFrom-Json
# Use $att.status / $att.database (or $($att.status)). Do not use brace-dot forms for properties.
$status = [string]$att.status
$db = [string]$att.database
if ($status.ToUpperInvariant() -ne 'SAFE') {
    throw ("HTTP/k6 BLOCKED: attestation status={0} (SAFE required)" -f $status)
}
$db = $db.ToLowerInvariant()
$allowed = @('ereview_loadtest', 'ereview_test')
if ($allowed -notcontains $db) {
    throw ("HTTP/k6 BLOCKED: attested database '{0}' is not allowlisted" -f $db)
}

$k6Cmd = Get-Command $K6Exe -ErrorAction SilentlyContinue
if (-not $k6Cmd) {
    throw 'k6 is not installed (or not on PATH). Harness PHP steps can still run. No traffic executed.'
}

$rid = "${env:LOADTEST_RUN_ID}"
$outDir = Join-Path $HarnessDir ("artifacts\{0}" -f $rid)
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$stdoutPath = Join-Path $outDir 'k6_stdout.txt'
$scriptPath = Join-Path $HarnessDir 'k6_mass_timeout.js'

Push-Location $ProjectRoot
try {
    $args = @(
        'run',
        '-e', ("LOADTEST_RUN_ID={0}" -f "${env:LOADTEST_RUN_ID}"),
        '-e', ("LOADTEST_DB_NAME={0}" -f "${env:LOADTEST_DB_NAME}"),
        '-e', ("LOADTEST_BASE_URL={0}" -f "${env:LOADTEST_BASE_URL}"),
        '-e', ("LOADTEST_N={0}" -f $N),
        '-e', ("LOADTEST_AUTOSAVE_FAILURE_RATE={0}" -f $AutosaveFailureRate),
        '-e', ("LOADTEST_DUPLICATE_SUBMIT_RATE={0}" -f $DuplicateSubmitRate),
        $scriptPath
    )
    Write-Host ("Running k6 N={0} run_id={1} attestation=SAFE db={2}" -f $N, $rid, $db)
    & $k6Cmd.Source @args 2>&1 | Tee-Object -FilePath $stdoutPath
    Write-Host ("k6 stdout saved: {0}" -f $stdoutPath)
}
finally {
    Pop-Location
}
