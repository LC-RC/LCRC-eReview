#Requires -Version 5.1
<#
.SYNOPSIS
  Parse-check all loadtest *.ps1 files for PowerShell 5.1 compatibility (no execution of seed/traffic).
#>
[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$HarnessDir = $PSScriptRoot
$files = Get-ChildItem -LiteralPath $HarnessDir -Filter '*.ps1' -File
$failed = 0

foreach ($f in $files) {
    $tokens = $null
    $errors = $null
    $null = [System.Management.Automation.Language.Parser]::ParseFile(
        $f.FullName,
        [ref]$tokens,
        [ref]$errors
    )
    if ($errors -and $errors.Count -gt 0) {
        $failed++
        Write-Host ("FAIL  parse {0}" -f $f.Name)
        foreach ($e in $errors) {
            Write-Host ("  {0}" -f $e.Message)
        }
        continue
    }

    $raw = Get-Content -LiteralPath $f.FullName -Raw
    # Unsafe: $env:VAR (no braces) adjacent to punctuation that PS 5.1 can misparse.
    # Safe: ${env:VAR} and Set-Item Env:... and assignment statements $env:VAR = ...
    $unsafeEnv = [regex]::Matches($raw, '(?<!\$)(?<!\{)\$env:[A-Za-z_][A-Za-z0-9_]*(?!\s*=)')
    $badEnv = @()
    foreach ($m in $unsafeEnv) {
        $end = $m.Index + $m.Length
        if ($end -lt $raw.Length) {
            $next = $raw[$end]
            if ($next -match '[:/\\.\(\)\[\]\{\},;]') {
                $badEnv += $m.Value
            }
        }
    }
    # Broken property access: brace + name + dot + prop is a literal variable name, not property access.
    $brokenProp = [regex]::Matches($raw, '\$\{[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*\}')
    # Skip this validator's own regex source line (contains the pattern as a string literal).
    if ($f.Name -eq 'Validate-LoadtestScripts.ps1') {
        $brokenProp = @($brokenProp | Where-Object {
            $lineStart = $raw.LastIndexOf("`n", $_.Index)
            if ($lineStart -lt 0) { $lineStart = 0 } else { $lineStart++ }
            $lineEnd = $raw.IndexOf("`n", $_.Index)
            if ($lineEnd -lt 0) { $lineEnd = $raw.Length }
            $line = $raw.Substring($lineStart, $lineEnd - $lineStart)
            $line -notmatch '\[regex\]::Matches'
        })
    }
    if ($badEnv.Count -gt 0 -or $brokenProp.Count -gt 0) {
        $failed++
        Write-Host ("FAIL  style {0}" -f $f.Name)
        foreach ($b in $badEnv) { Write-Host ("  unsafe env access near punctuation: {0}" -f $b) }
        foreach ($b in $brokenProp) {
            Write-Host ('  broken brace-dot property syntax: ' + $b.Value)
        }
    }
    else {
        Write-Host ("PASS  parse {0}" -f $f.Name)
    }
}

if ($failed -gt 0) {
    Write-Host ("POWERSHELL VALIDATION FAIL ({0})" -f $failed)
    exit 1
}
Write-Host 'POWERSHELL VALIDATION PASS'
exit 0
