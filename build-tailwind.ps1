# Build Tailwind CSS without requiring npm in PATH
# Run: .\build-tailwind.ps1   or   powershell -ExecutionPolicy Bypass -File build-tailwind.ps1

$nodePaths = @(
    "$env:ProgramFiles\nodejs",
    "${env:ProgramFiles(x86)}\nodejs",
    "$env:APPDATA\npm"
)
foreach ($p in $nodePaths) {
    if (Test-Path $p) { $env:Path = "$p;$env:Path"; break }
}

$cssDir = Join-Path $PSScriptRoot "assets\css"
$inputCss = Join-Path $PSScriptRoot "src\input.css"
$outputCss = Join-Path $cssDir "tailwind.css"

if (-not (Test-Path "node_modules\tailwindcss\lib\cli.js")) {
    Write-Host "Installing dependencies first..." -ForegroundColor Yellow
    & npm install
    if ($LASTEXITCODE -ne 0) {
        Write-Host "npm install failed. Make sure Node.js is installed from https://nodejs.org" -ForegroundColor Red
        exit 1
    }
}

& node node_modules\tailwindcss\lib\cli.js -i $inputCss -o $outputCss
if ($LASTEXITCODE -eq 0) {
    Write-Host "Tailwind build done. Refresh your browser (Ctrl+Shift+R)." -ForegroundColor Green
} else {
    Write-Host "Build failed." -ForegroundColor Red
    exit 1
}
