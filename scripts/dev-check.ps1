# dev-check.ps1 - Targeted check for changed files only
# Usage:
#   scripts/dev-check.ps1                        -> lint + cache clear + e-sign pipeline gate
#   scripts/dev-check.ps1 -Full                  -> + full test suite
#   scripts/dev-check.ps1 -SkipPipelineGate      -> skip the e-sign pipeline gate
#                                                   (use only when the test diff
#                                                   landed in a previous commit and
#                                                   this one is a follow-up cleanup)
#
# Run from repo root (PowerShell)

param(
    [switch]$Full,
    [switch]$SkipPipelineGate
)

$ErrorActionPreference = 'Stop'
$failed = $false

Write-Host '=== DEV CHECK ===' -ForegroundColor Cyan

# 0) PHP present
php -v | Out-Null

# -- Collect changed files --
$changedPhp = @()
$changedBlade = @()

if (Get-Command git -ErrorAction SilentlyContinue) {
    # Staged + unstaged + untracked changes vs HEAD
    $allChanged = @()
    $allChanged += git diff --name-only --diff-filter=ACMRT HEAD 2>$null
    $allChanged += git diff --name-only --cached 2>$null
    $allChanged += git ls-files --others --exclude-standard 2>$null
    $allChanged = $allChanged | Sort-Object -Unique | Where-Object { $_ }

    $changedPhp   = $allChanged | Where-Object { $_ -match '\.php$' }
    $changedBlade = $allChanged | Where-Object { $_ -match '\.blade\.php$' }

    if ($allChanged.Count -eq 0) {
        Write-Host ''
        Write-Host 'No changed files detected.' -ForegroundColor Green
    } else {
        Write-Host ''
        Write-Host "Changed files: $($allChanged.Count)" -ForegroundColor DarkGray
    }
} else {
    Write-Host 'git not found; skipping changed-file detection.' -ForegroundColor DarkYellow
}

# -- 1. Lint changed PHP files --
Write-Host ''
Write-Host '1. Lint PHP files' -ForegroundColor Yellow
$lintCount = 0
foreach ($f in $changedPhp) {
    if (Test-Path $f) {
        $result = php -l $f 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "   FAIL: $f" -ForegroundColor Red
            Write-Host "   $result" -ForegroundColor Red
            $failed = $true
        } else {
            $lintCount++
        }
    }
}
if ($lintCount -gt 0) {
    Write-Host "   $lintCount file(s) lint OK" -ForegroundColor Green
} elseif ($changedPhp.Count -eq 0) {
    Write-Host '   No PHP files changed' -ForegroundColor DarkGray
}

# -- 2. Clear caches --
Write-Host ''
Write-Host '2. Clear caches' -ForegroundColor Yellow
php artisan optimize:clear 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host '   artisan optimize:clear failed' -ForegroundColor Red
    $failed = $true
} else {
    Write-Host '   Caches cleared' -ForegroundColor Green
}

# -- 3. Route check (only if routes or controllers changed) --
$routeFiles = $changedPhp | Where-Object { $_ -match 'routes[/\\]' -or $_ -match 'Controllers[/\\]' }
if ($routeFiles.Count -gt 0) {
    Write-Host ''
    Write-Host '3. Route check' -ForegroundColor Yellow
    $routeResult = php artisan route:clear 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host '   Route compilation failed!' -ForegroundColor Red
        Write-Host "   $routeResult" -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host '   Routes compile OK' -ForegroundColor Green
    }
} else {
    Write-Host ''
    Write-Host '3. Route check -- skipped (no route/controller changes)' -ForegroundColor DarkGray
}

# -- 4. View check (only if blade files changed) --
if ($changedBlade.Count -gt 0) {
    Write-Host ''
    Write-Host '4. View compilation check' -ForegroundColor Yellow
    $viewResult = php artisan view:cache 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host '   View compilation failed!' -ForegroundColor Red
        Write-Host "   $viewResult" -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host '   Views compile OK' -ForegroundColor Green
    }
    # Clean up compiled views
    php artisan view:clear 2>&1 | Out-Null
} else {
    Write-Host ''
    Write-Host '4. View check -- skipped (no blade changes)' -ForegroundColor DarkGray
}

# -- 5. Tests --
if ($Full) {
    Write-Host ''
    Write-Host '5. Full test suite' -ForegroundColor Yellow
    php artisan test
    if ($LASTEXITCODE -ne 0) {
        $failed = $true
    }
} else {
    Write-Host ''
    Write-Host '5. Tests -- skipped (use -Full to run all 894 tests)' -ForegroundColor DarkGray
}

# -- 6. E-sign pipeline gate --
#
# The recipient-signing integration moat (Template → CdsDraft → blade →
# WebTemplateData → SurfaceNormalizer → LetterheadRefresher →
# InsertableBlockRenderer → RoleBlockExpansionService → SigningController
# → sign.blade.php) is what the audit
# .ai/audits/esign-reset-investigation-2026-05-27.md identified as
# untested before the reset. The gate enforces that any change to one of
# the pipeline files lands together with a test diff in
# `tests/Feature/Docuperfect/SigningView/` — so "tests pass" can never
# again coexist with "feature broken in the browser".
#
# Use `-SkipPipelineGate` only when the test diff landed in a previous
# commit and this one is a follow-up cleanup (e.g. doc-only commit
# updating CHAT_STARTER). The gate ALWAYS runs in CI even when this
# flag is set locally — Commit 6's CI workflow rejects the flag.
$pipelineFiles = @(
    'app/Models/Docuperfect/Template.php',
    'app/Models/Docuperfect/CdsDraft.php',
    'app/Services/Docuperfect/SurfaceNormalizer.php',
    'app/Services/Docuperfect/SignatureSurfaceNormalizer.php',
    'app/Services/Docuperfect/LetterheadRefresher.php',
    'app/Services/Docuperfect/InsertableBlockRenderer.php',
    'app/Services/Docuperfect/RoleBlockDetectionService.php',
    'app/Services/Docuperfect/RoleBlockExpansionService.php',
    'app/Services/Docuperfect/MergedHtmlFreshnessGuard.php',
    'app/Http/Controllers/Docuperfect/SigningController.php'
)

if ($SkipPipelineGate) {
    Write-Host ''
    Write-Host '6. E-sign pipeline gate -- skipped (-SkipPipelineGate)' -ForegroundColor DarkGray
} else {
    Write-Host ''
    Write-Host '6. E-sign pipeline gate' -ForegroundColor Yellow

    # Normalise file paths for cross-platform matching (git always emits
    # forward slashes; on Windows the working-copy paths may carry mixed
    # separators when displayed). Use forward-slash form everywhere.
    $changedNorm = $allChanged | ForEach-Object { ($_ -replace '\\', '/').ToLower() }

    $pipelineChanged = @()
    foreach ($pf in $pipelineFiles) {
        $pfNorm = $pf.ToLower()
        if ($changedNorm -contains $pfNorm) {
            $pipelineChanged += $pf
        }
    }

    if ($pipelineChanged.Count -gt 0) {
        $testChanged = $changedNorm | Where-Object {
            $_ -like 'tests/feature/docuperfect/signingview/*' -or
            $_ -like 'tests/concerns/buildssigningsession.php' -or
            $_ -like 'tests/fixtures/templates/*'
        }
        if ($testChanged.Count -eq 0) {
            Write-Host '   FAIL: pipeline files changed without a corresponding test diff' -ForegroundColor Red
            Write-Host '   in tests/Feature/Docuperfect/SigningView/ (or the supporting' -ForegroundColor Red
            Write-Host '   tests/Concerns + tests/Fixtures used by the contract suite).' -ForegroundColor Red
            Write-Host '' -ForegroundColor Red
            Write-Host '   Pipeline files changed:' -ForegroundColor Red
            foreach ($f in $pipelineChanged) {
                Write-Host "     - $f" -ForegroundColor Red
            }
            Write-Host '' -ForegroundColor Red
            Write-Host '   The integration moat must stay under test. Either add a' -ForegroundColor Red
            Write-Host '   test that exercises the change OR re-run with' -ForegroundColor Red
            Write-Host '   `-SkipPipelineGate` if the test landed in a previous commit.' -ForegroundColor Red
            $failed = $true
        } else {
            Write-Host "   $($pipelineChanged.Count) pipeline file(s) changed, $($testChanged.Count) test file(s) updated" -ForegroundColor Green
        }
    } else {
        Write-Host '   No pipeline-file changes' -ForegroundColor DarkGray
    }
}

# -- Result --
Write-Host ''
if ($failed) {
    Write-Host '=== DEV CHECK FAILED ===' -ForegroundColor Red
    exit 1
} else {
    Write-Host '=== DEV CHECK OK ===' -ForegroundColor Green
}
