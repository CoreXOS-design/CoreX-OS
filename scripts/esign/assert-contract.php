<?php

/**
 * §-CONTRACT ASSERTION HARNESS — .ai/qa/esign-contract-walk-test/README.md
 *
 * Asserts whether the data-role-block contract is ACTUALLY IN FORCE, rather than merely
 * deployed. The distinction is the whole point of the exercise: the contract engine has been
 * on main since late May, and for six weeks "the contract engine is built" was an untested
 * claim, because the legacy clustering fallback produces plausible-looking output. You cannot
 * assert this by looking at a rendered document. You assert it by driving the renderer and
 * reading the log.
 *
 * THE KNIFE-EDGE (README): if `RoleBlockExpansionService: rendering unnormalised template via
 * legacy clustering` is emitted, the contract did not take.
 *
 * Usage (from an environment root, e.g. /corex-qa1):
 *     php scripts/esign/assert-contract.php
 *
 * Read-only. Renders in memory; writes nothing.
 */

$root = getcwd();
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Support\Facades\Log;

$taggedHtml = function (Template $t): ?string {
    $es = $t->editor_state;
    if (is_string($es)) {
        $es = json_decode($es, true);
    }

    return is_array($es) ? ($es['tagged_html'] ?? null) : null;
};

echo "§-CONTRACT ASSERTION — " . config('app.env') . " / " . DB::connection()->getDatabaseName() . "\n";
echo str_repeat('=', 78) . "\n\n";

// ── PRECONDITIONS ────────────────────────────────────────────────────────
echo "PRECONDITIONS\n";
foreach (['4d5eb28c' => 'importer stamps the contract', '1fe10836' => 'contract-driven renderer'] as $sha => $what) {
    exec("git merge-base --is-ancestor {$sha} HEAD 2>/dev/null", $o, $rc);
    printf("  %-10s %-32s %s\n", $sha, $what, $rc === 0 ? 'PRESENT' : 'ABSENT ✗');
}

// ── SECTION A — coverage: does the contract exist in the DATA? ───────────
$all = Template::withoutGlobalScopes()->whereNull('deleted_at')->get();
$cds = $all->where('template_type', 'cds');
$withTagged = $all->filter(fn ($t) => ! empty($taggedHtml($t)));
$withContract = $all->filter(fn ($t) => str_contains((string) $taggedHtml($t), 'data-role-block'));

echo "\nSECTION A — CONTRACT COVERAGE\n";
printf("  templates                       : %d\n", $all->count());
printf("  ...template_type = 'cds'        : %d   (what the backfill targets)\n", $cds->count());
printf("  ...with editor_state.tagged_html: %d\n", $withTagged->count());
printf("  ...CARRYING data-role-block     : %d   <-- must be > 0 for B/C to mean anything\n", $withContract->count());

foreach ($withContract as $t) {
    $html = (string) $taggedHtml($t);
    preg_match_all('/data-role-block="([^"]+)"/', $html, $m);
    preg_match_all('/data-role-block-segment="([^"]+)"/', $html, $seg);
    echo "\n  #{$t->id} {$t->name}\n";
    echo "      role blocks : ";
    foreach (array_count_values($m[1]) as $role => $n) {
        echo "{$role}×{$n} ";
    }
    echo "\n      segments    : " . (count($seg[1]) ? implode(', ', array_unique($seg[1])) : '(none)') . "\n";
    echo "      signature-line surfaces: " . substr_count($html, 'signature-line') . "\n";
}

// ── SECTION B — the knife-edge: which path does the renderer TAKE? ───────
$legacyHits = [];
Log::listen(function ($e) use (&$legacyHits) {
    if (str_contains((string) $e->message, 'legacy clustering')) {
        $legacyHits[] = $e->context['template_id'] ?? '?';
    }
});

$svc = app(RoleBlockExpansionService::class);

// TWO SELLERS — the multi-party case the contract exists for.
$recipients = collect([
    new SignatureRequest(['party_role' => 'seller', 'signer_name' => 'Thandi Mkhize']),
    new SignatureRequest(['party_role' => 'seller', 'signer_name' => 'Pieter van der Merwe']),
]);

$renderable = $all->filter(fn ($t) => ! empty($taggedHtml($t)) || ! empty($t->html));

echo "\nSECTION B — THE KNIFE-EDGE (driving the renderer, two sellers)\n";
$viaContract = $viaLegacy = 0;

foreach ($renderable as $t) {
    $html = $taggedHtml($t) ?: (string) $t->html;
    $before = count($legacyHits);

    try {
        $out = $svc->expandWithLooping($t, $html, $recipients);
    } catch (\Throwable $e) {
        printf("  #%-3s %-38s THREW %s\n", $t->id, substr($t->name, 0, 38), class_basename($e));
        continue;
    }

    $hitLegacy = count($legacyHits) > $before;
    $hitLegacy ? $viaLegacy++ : $viaContract++;

    // When the contract takes, prove the per-party expansion actually happened.
    $blocks = substr_count($out, 'data-role-block');
    printf("  #%-3s %-38s contract=%-3s %s%s\n",
        $t->id, substr($t->name, 0, 38),
        str_contains($html, 'data-role-block') ? 'yes' : 'NO',
        $hitLegacy ? 'LEGACY CLUSTERING ⚠' : 'contract path ✓',
        $hitLegacy ? '' : "  (role blocks after expansion: {$blocks})");
}

// ── VERDICT ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 78) . "\nVERDICT\n";
printf("  rendered via the CONTRACT path : %d\n", $viaContract);
printf("  rendered via LEGACY CLUSTERING : %d\n", $viaLegacy);
printf("  knife-edge log lines emitted   : %d\n", count($legacyHits));

$clean = $withContract->isNotEmpty() && $viaLegacy === 0 && $viaContract > 0;
echo "\n  " . ($clean
    ? "ASSERTED CLEAN — the contract is in force; zero legacy-clustering lines."
    : "DEVIATION — the contract is NOT in force. "
      . ($withContract->isEmpty()
         ? "Contract coverage is ZERO: no template carries data-role-block, so sections B–E have no subject."
         : "The renderer still fell back to legacy clustering."))
    . "\n";

exit($clean ? 0 : 1);
