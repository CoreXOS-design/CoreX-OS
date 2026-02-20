# Run from repo root (PowerShell)
$ErrorActionPreference = 'Stop'

Write-Host '=== DEV CHECK ===' -ForegroundColor Cyan

# 0) PHP present
php -v | Out-Null

Write-Host "`n1) Lint changed PHP files (best effort)" -ForegroundColor Yellow
if (Get-Command git -ErrorAction SilentlyContinue) {
  $changed = git diff --name-only --diff-filter=ACMRT HEAD | Where-Object { $_ -match '\.php$' }
  foreach ($f in $changed) {
    if (Test-Path $f) {
      php -l $f
      if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $f" }
    }
  }
} else {
  Write-Host "git not found; skipping changed-file lint." -ForegroundColor DarkYellow
}

Write-Host "`n2) Clear caches" -ForegroundColor Yellow
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw "artisan optimize:clear failed" }

Write-Host "`n3) Run tests (if available)" -ForegroundColor Yellow
php artisan test
if ($LASTEXITCODE -ne 0) { throw "Tests failed" }

Write-Host "`n=== DEV CHECK OK ===" -ForegroundColor Green