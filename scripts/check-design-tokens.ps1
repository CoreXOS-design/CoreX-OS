# check-design-tokens.ps1 - F.7 regression guard for UI_DESIGN_SYSTEM.md
# Fails if any Blade view under the scanned root contains a naked hex
# colour for anything the design system covers.
#
# Allowed: var(--token, #fallback) pattern per UI_DESIGN_SYSTEM.md section 5.10
# Allowed: color: #fff inside inverse-pattern brand surfaces (section 1.3)
# Forbidden: bare hex like 'background: #abc' or 'color: #abc' outside var().
#
# Default scope: resources/views/corex/market-intelligence/  (the F.7 surface).
# Use -Path to scan a different subtree:
#   scripts/check-design-tokens.ps1 -Path resources/views/corex/

param(
    [string]$Path = 'resources/views/corex/market-intelligence/'
)

$ErrorActionPreference = 'Continue'
Write-Host ("=== DESIGN TOKEN CHECK (" + $Path + ") ===") -ForegroundColor Cyan

$violations = 0
$root = $Path

if (-not (Test-Path $root)) {
    Write-Host ("Root not found: " + $root) -ForegroundColor DarkYellow
    exit 0
}

$bladeFiles = Get-ChildItem -Path $root -Recurse -Filter '*.blade.php' -File

$patBackground = '(?<!var\()background:\s*#[0-9a-fA-F]{3,8}'
$patColorNonWhite = '(?<!var\()color:\s*#(?!fff\b|FFF\b|ffffff\b|FFFFFF\b)[0-9a-fA-F]{3,8}'
$patPlusJakarta = "font-family:\s*['""]Plus Jakarta"

foreach ($file in $bladeFiles) {
    $content = Get-Content -LiteralPath $file.FullName -Raw
    $relPath = $file.FullName.Replace((Get-Location).Path + '\', '')

    $patterns = @($patBackground, $patColorNonWhite, $patPlusJakarta)
    foreach ($pat in $patterns) {
        $matches = [regex]::Matches($content, $pat)
        foreach ($m in $matches) {
            $upto = $content.Substring(0, $m.Index)
            $line = ($upto -split "`n").Count
            Write-Host ("  FAIL: " + $relPath + ":" + $line + "  " + $m.Value) -ForegroundColor Red
            $violations++
        }
    }
}

Write-Host ''
if ($violations -eq 0) {
    Write-Host ("Design token check: CLEAN (0 violations across " + $bladeFiles.Count + " files)") -ForegroundColor Green
    exit 0
}
Write-Host ("Design token check: FAILED (" + $violations + " violations)") -ForegroundColor Red
Write-Host 'Use the var(--token, #fallback) pattern per UI_DESIGN_SYSTEM.md section 5.10.' -ForegroundColor Yellow
exit 1
