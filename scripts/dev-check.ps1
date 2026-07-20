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
# WebTemplateData → SignatureSurfaceNormalizer → LetterheadRefresher →
# InsertableBlockRenderer → RoleBlockNormalizer → RoleBlockExpansionService →
# SigningController → sign.blade.php) is what the audit
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
    'app/Services/Docuperfect/SignatureSurfaceNormalizer.php',
    'app/Services/Docuperfect/LetterheadRefresher.php',
    'app/Services/Docuperfect/InsertableBlockRenderer.php',
    'app/Services/Docuperfect/RoleBlockDetectionService.php',
    'app/Services/Docuperfect/RoleBlockExpansionService.php',
    'app/Services/Docuperfect/RoleBlockNormalizer.php',
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

# -- 7. Portal sync cost gate --------------------------------------------------
# The SAME discipline as the e-sign moat above, for the same reason: a class of
# regression that no assertion catches and no pipeline turns red.
#
# A Refresh of a listing where NOTHING changed must cost exactly one P24 call —
# the listing POST. That contract has been broken twice in production, and both
# times the only alarm was an agent saying "Refresh feels slow":
#
#   1. Every refresh re-uploaded the whole photo gallery  (60s+ per refresh)
#      -> fixed by properties.p24_image_signature
#   2. Then an unconditional agent profile push + agent photo upload was added
#      to the submit path, per agent, on every refresh -- quietly undoing it,
#      and putting P24's 15-120s GET /agencies/{id}/agents back on the hot path.
#
# Cost is invisible to a green test run. So any change to the portal sync path
# must land with a diff in tests/Feature/Syndication/ -- where
# Property24RefreshCostTest asserts the one-call budget outright.
$portalSyncFiles = @(
    'app/Services/Syndication/Property24/Property24SyndicationService.php',
    'app/Services/Syndication/Property24/Property24ListingMapper.php',
    'app/Services/Syndication/Property24/Property24ApiClient.php',
    'app/Services/PrivateProperty/PrivatePropertySyndicationService.php',
    'app/Services/PrivateProperty/PrivatePropertyListingMapper.php',
    'app/Jobs/SubmitListingToProperty24.php'
)

if ($SkipPipelineGate) {
    Write-Host ''
    Write-Host '7. Portal sync cost gate -- skipped (-SkipPipelineGate)' -ForegroundColor DarkGray
} else {
    Write-Host ''
    Write-Host '7. Portal sync cost gate' -ForegroundColor Yellow

    $changedNorm = $allChanged | ForEach-Object { ($_ -replace '\\', '/').ToLower() }

    $portalChanged = @()
    foreach ($pf in $portalSyncFiles) {
        if ($changedNorm -contains $pf.ToLower()) {
            $portalChanged += $pf
        }
    }

    if ($portalChanged.Count -gt 0) {
        $syndTests = $changedNorm | Where-Object { $_ -like 'tests/feature/syndication/*' }

        if ($syndTests.Count -eq 0) {
            Write-Host '   FAIL: portal sync files changed without a test diff in' -ForegroundColor Red
            Write-Host '   tests/Feature/Syndication/' -ForegroundColor Red
            Write-Host '' -ForegroundColor Red
            Write-Host '   Portal sync files changed:' -ForegroundColor Red
            foreach ($f in $portalChanged) {
                Write-Host "     - $f" -ForegroundColor Red
            }
            Write-Host '' -ForegroundColor Red
            Write-Host '   A refresh of an UNCHANGED listing must cost exactly ONE P24' -ForegroundColor Red
            Write-Host '   call (the listing POST). If your change adds a call that fires' -ForegroundColor Red
            Write-Host '   when nothing changed, gate it on a signature -- never re-send' -ForegroundColor Red
            Write-Host '   bytes the portal already holds. Property24RefreshCostTest is' -ForegroundColor Red
            Write-Host '   the lock; extend it to cover what you changed.' -ForegroundColor Red
            $failed = $true
        } else {
            Write-Host "   $($portalChanged.Count) portal sync file(s) changed, $($syndTests.Count) test file(s) updated" -ForegroundColor Green
        }
    } else {
        Write-Host '   No portal-sync-file changes' -ForegroundColor DarkGray
    }
}

# 8. AT-321 property audit bypass gate
#
# The property audit trail must log EVERY change (who/when). A raw write that
# skips the Eloquent observer -- DB::table('properties')->update(...), or a
# ->updateQuietly()/->saveQuietly() on a Property -- can slip a change past the
# app-layer audit. The DB trigger is the runtime backstop; THIS gate is the
# review-time one: a new raw property write must land with an audit test proving
# it still logs, OR go through Property::auditedQuietUpdate().
if ($SkipPipelineGate) {
    Write-Host ''
    Write-Host '8. Property audit bypass gate (AT-321) -- skipped (-SkipPipelineGate)' -ForegroundColor DarkGray
} else {
    Write-Host ''
    Write-Host '8. Property audit bypass gate (AT-321)' -ForegroundColor Yellow

    $diffLines = @()
    $diffLines += git diff --diff-filter=ACMRT HEAD 2>$null
    $diffLines += git diff --cached 2>$null
    $addedLines = $diffLines | Where-Object { $_ -match '^\+' -and $_ -notmatch '^\+\+\+' }

    $rawWrites = $addedLines | Where-Object {
        ($_ -match "DB::table\(\s*['""]properties['""]\s*\)") -or
        (($_ -match '(updateQuietly|saveQuietly)\(') -and ($_ -match 'propert'))
    } | Where-Object {
        # The sanctioned helper and the trigger de-dupe plumbing are allowed.
        ($_ -notmatch 'auditedQuietUpdate') -and ($_ -notmatch 'corex_audit_handled')
    }

    if ($rawWrites.Count -gt 0) {
        $auditTests = $changedNorm | Where-Object {
            ($_ -like 'tests/feature/properties/audit/*') -or ($_ -like '*propertyaudit*')
        }
        if ($auditTests.Count -eq 0) {
            Write-Host '   FAIL: a raw property write that bypasses the audit observer was' -ForegroundColor Red
            Write-Host '   added without an audit test in tests/Feature/Properties/Audit/.' -ForegroundColor Red
            Write-Host '' -ForegroundColor Red
            foreach ($l in $rawWrites) { Write-Host "     $($l.Trim())" -ForegroundColor Red }
            Write-Host '' -ForegroundColor Red
            Write-Host '   Route the write through Property::auditedQuietUpdate(), or add a' -ForegroundColor Red
            Write-Host '   test proving the change still produces an attributed audit row.' -ForegroundColor Red
            $failed = $true
        } else {
            Write-Host "   $($rawWrites.Count) raw property write(s) changed, audit test present" -ForegroundColor Green
        }
    } else {
        Write-Host '   No new raw property writes' -ForegroundColor DarkGray
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
