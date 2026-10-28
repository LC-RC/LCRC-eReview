#Requires -Version 5.1
<#
.SYNOPSIS
  Run k6 examination traffic (Regular or Diagnostic) only after SAFE attestation.

.DESCRIPTION
  Uses k6_exam_traffic.js (HTTP approximation — not a browser).
  Does not install k6. Does not target production by default.

.EXAMPLE
  .\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode regular -N 100 -Scenario mixed
#>
[CmdletBinding()]
param(
    [ValidateSet('regular', 'diagnostic')]
    [string]$Mode = 'regular',

    [int]$N = 5,

    [ValidateSet('timeout_wave', 'mixed', 'polling', 'rapid_save', 'normal_save')]
    [string]$Scenario = 'timeout_wave',

    [string]$RunId = '',

    [double]$AutosaveFailureRate = 0.0,

    [double]$DuplicateSubmitRate = 0.15,

    [string]$K6Exe = 'k6',

    [string]$Script = 'k6_exam_traffic.js'
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

Set-Item -Path Env:LOADTEST_MODE -Value $Mode
Set-Item -Path Env:LOADTEST_SCENARIO -Value $Scenario

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
    throw 'LOADTEST_RUN_ID is empty. Run seed/bootstrap/start/align/build first.'
}
if ([string]::IsNullOrWhiteSpace("${env:LOADTEST_BASE_URL}")) {
    throw 'LOADTEST_BASE_URL is required (no default).'
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
    throw 'HttpPreflight did not return SAFE. HTTP/k6 BLOCKED.'
}

$attestationPath = Join-Path $HarnessDir 'artifacts\http_attestation.json'
if (-not (Test-Path -LiteralPath $attestationPath)) {
    throw 'Missing artifacts/http_attestation.json after preflight'
}
$att = Get-Content -LiteralPath $attestationPath -Raw | ConvertFrom-Json
$status = [string]$att.status
$db = ([string]$att.database).ToLowerInvariant()
if ($status.ToUpperInvariant() -ne 'SAFE') {
    throw ("HTTP/k6 BLOCKED: attestation status={0}" -f $status)
}
if ($allowedDbs -notcontains $db) {
    throw ("HTTP/k6 BLOCKED: attested database '{0}' not allowlisted" -f $db)
}

$k6Cmd = Get-Command $K6Exe -ErrorAction SilentlyContinue
if (-not $k6Cmd) {
    Write-Warning 'k6 is not installed (or not on PATH). No traffic executed.'
    Write-Host '[WARN] k6 not installed — capacity test not executed.'
    exit 0
}

$rid = "${env:LOADTEST_RUN_ID}"
$outDir = Join-Path $HarnessDir ("artifacts\{0}" -f $rid)
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$stdoutPath = Join-Path $outDir 'k6_stdout.txt'
$scriptPath = Join-Path $HarnessDir $Script
if (-not (Test-Path -LiteralPath $scriptPath)) {
    throw "k6 script not found: $scriptPath"
}

Push-Location $ProjectRoot
try {
    $args = @(
        'run',
        '-e', ("LOADTEST_RUN_ID={0}" -f "${env:LOADTEST_RUN_ID}"),
        '-e', ("LOADTEST_DB_NAME={0}" -f "${env:LOADTEST_DB_NAME}"),
        '-e', ("LOADTEST_BASE_URL={0}" -f "${env:LOADTEST_BASE_URL}"),
        '-e', ("LOADTEST_N={0}" -f $N),
        '-e', ("LOADTEST_MODE={0}" -f $Mode),
        '-e', ("LOADTEST_SCENARIO={0}" -f $Scenario),
        '-e', ("LOADTEST_AUTOSAVE_FAILURE_RATE={0}" -f $AutosaveFailureRate),
        '-e', ("LOADTEST_DUPLICATE_SUBMIT_RATE={0}" -f $DuplicateSubmitRate),
        $scriptPath
    )
    Write-Host ("Running k6 mode={0} scenario={1} N={2} run_id={3} db={4}" -f $Mode, $Scenario, $N, $rid, $db)
    Write-Host 'NOTE: HTTP approximation only — not a real browser certification.'
    & $k6Cmd.Source @args 2>&1 | Tee-Object -FilePath $stdoutPath
    Write-Host ("k6 stdout saved: {0}" -f $stdoutPath)
}
finally {
    Pop-Location
}
