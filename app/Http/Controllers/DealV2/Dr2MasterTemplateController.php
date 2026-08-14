<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Services\DealV2\Dr2ConditionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-334 Phase 2 — the settings screen for the GLOBAL composable master pipeline template
 * that the Deal Structure reads from (Dr2ConditionCatalog). Global-only editing: the
 * condition list (labels) + the steps-per-condition templates. Option-driven expansion
 * (cash payment fan-out, deposit/proof toggles) stays PROCEDURAL and is not edited here.
 * Editing applies to NEW deals only — existing deals are never re-flowed (that is the
 * deferred Restructure).
 */
class Dr2MasterTemplateController extends Controller
{
    public function __construct(private readonly Dr2ConditionCatalog $catalog)
    {
    }

    public function edit(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        return view('deals-v2.pipeline-setup.master', [
            'master'          => $this->catalog->editableMaster(),
            'completionTypes' => Dr2ConditionCatalog::COMPLETION_TYPES,
            'statusTriggers'  => Dr2ConditionCatalog::STATUS_TRIGGERS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $groups = json_decode((string) $request->input('payload', ''), true);
        if (! is_array($groups) || empty($groups)) {
            return back()->with('error', 'Nothing to save — the template payload was empty or malformed.');
        }

        // Only the known condition groups may be edited (new conditions need option-schema +
        // structure-form work — a later phase). Guards the key set.
        $allowedKeys = array_merge([Dr2ConditionCatalog::BASE_KEY], Dr2ConditionCatalog::CONDITION_ORDER);
        $validCompletions = array_keys(Dr2ConditionCatalog::COMPLETION_TYPES);
        $validStatus      = array_keys(Dr2ConditionCatalog::STATUS_TRIGGERS);

        $clean       = [];
        $allKeys     = [];   // every step_key in the template (for reference + uniqueness)
        $grantMarkers = 0;
        $anchors      = 0;

        foreach ($groups as $g) {
            $key = $g['key'] ?? null;
            if (! in_array($key, $allowedKeys, true)) {
                return back()->with('error', "Unknown condition group: ".e((string) $key));
            }
            $label = trim((string) ($g['label'] ?? ''));
            if ($label === '') {
                return back()->with('error', 'Every condition needs a label.');
            }

            $steps = [];
            foreach (($g['steps'] ?? []) as $s) {
                $stepKey = trim((string) ($s['step_key'] ?? ''));
                $name    = trim((string) ($s['name'] ?? ''));
                if ($stepKey === '' || $name === '') {
                    return back()->with('error', 'Every step needs a key and a name.');
                }
                if (isset($allKeys[$stepKey])) {
                    return back()->with('error', "Duplicate step key \"{$stepKey}\" — step keys must be unique across the template.");
                }
                $allKeys[$stepKey] = true;

                $offset = (int) ($s['days_offset'] ?? 0);
                if ($offset < 0) {
                    return back()->with('error', "Step \"{$name}\" has a negative offset — offsets must be 0 or more.");
                }

                $completion = $s['completion_type'] ?? 'manual_tick';
                if (! in_array($completion, $validCompletions, true)) {
                    return back()->with('error', "Step \"{$name}\" has an unknown completion type.");
                }
                $status = $s['status_trigger'] ?: null;
                if ($status !== null && ! in_array($status, $validStatus, true)) {
                    return back()->with('error', "Step \"{$name}\" has an unknown status trigger.");
                }

                $isGrant  = ! empty($s['is_grant_marker']);
                $isAnchor = ! empty($s['is_anchor']);
                $grantMarkers += $isGrant ? 1 : 0;
                $anchors      += $isAnchor ? 1 : 0;

                $steps[] = [
                    'step_key'            => $stepKey,
                    'name'                => $name,
                    'follows_key'         => ($s['follows_key'] ?? null) ?: null,
                    'deps_keys'           => array_values(array_filter(array_map(
                        fn ($d) => trim((string) $d), (array) ($s['deps_keys'] ?? [])
                    ), fn ($d) => $d !== '')),
                    'days_offset'         => $offset,
                    'is_milestone'        => ! empty($s['is_milestone']),
                    'is_suspensive'       => ! empty($s['is_suspensive']),
                    'is_anchor'           => $isAnchor,
                    'is_grant_marker'     => $isGrant,
                    'completion_type'     => $completion,
                    'status_trigger'      => $status,
                    'manual_due_option'   => ($s['manual_due_option'] ?? null) ?: null,
                    'position'            => (int) ($s['position'] ?? 0),
                    // Procedural markers — preserved verbatim, never edited in the UI.
                    'requires_option'     => ($s['requires_option'] ?? null) ?: null,
                    'requires_funds_mode' => ($s['requires_funds_mode'] ?? null) ?: null,
                    'expand'              => ($s['expand'] ?? null) ?: null,
                ];
            }

            $clean[] = ['key' => $key, 'label' => $label, 'steps' => $steps];
        }

        // ── Guardrails ──
        if ($grantMarkers !== 1) {
            return back()->with('error', "There must be exactly ONE grant-convergence marker — found {$grantMarkers}.");
        }
        if ($anchors !== 1) {
            return back()->with('error', "There must be exactly ONE anchor (Deal Signed) step — found {$anchors}.");
        }
        if ($cycle = $this->firstFollowsCycle($clean, $allKeys)) {
            return back()->with('error', "Cyclic dependency detected involving \"{$cycle}\" — a step cannot (transitively) follow itself.");
        }

        $this->catalog->saveMaster($clean);

        return redirect()->route('deals-v2.pipeline.master')
            ->with('status', 'Master pipeline template saved. New deals use it immediately; existing deals are unchanged.');
    }

    /**
     * Detect a cycle in the predecessor graph (follows + AND-gate deps). '__grant__' resolves
     * to the grant-marker step. References to unknown keys are ignored (they simply never
     * resolve at compose time). Returns the first step key on a cycle, or null.
     */
    private function firstFollowsCycle(array $groups, array $allKeys): ?string
    {
        // Resolve the grant marker key for the __grant__ sentinel.
        $grantKey = null;
        $edges = [];
        foreach ($groups as $g) {
            foreach ($g['steps'] as $s) {
                if ($s['is_grant_marker']) {
                    $grantKey = $s['step_key'];
                }
            }
        }
        foreach ($groups as $g) {
            foreach ($g['steps'] as $s) {
                $preds = [];
                $f = $s['follows_key'];
                if ($f === '__grant__') { $f = $grantKey; }
                if ($f && isset($allKeys[$f])) { $preds[] = $f; }
                foreach ($s['deps_keys'] as $d) {
                    $d = $d === '__grant__' ? $grantKey : $d;
                    if ($d && isset($allKeys[$d])) { $preds[] = $d; }
                }
                $edges[$s['step_key']] = array_values(array_unique($preds));
            }
        }

        $state = []; // 0=unvisited,1=in-stack,2=done
        $onCycle = null;
        $dfs = function ($node) use (&$dfs, &$state, &$edges, &$onCycle) {
            $state[$node] = 1;
            foreach ($edges[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) { $onCycle = $next; return true; }
                if (($state[$next] ?? 0) === 0 && $dfs($next)) { return true; }
            }
            $state[$node] = 2;
            return false;
        };
        foreach (array_keys($edges) as $node) {
            if (($state[$node] ?? 0) === 0 && $dfs($node)) {
                return $onCycle;
            }
        }
        return null;
    }
}
