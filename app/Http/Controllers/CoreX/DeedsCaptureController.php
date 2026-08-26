<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TvaContactCapture;
use App\Models\PropertyNote;
use App\Services\ContactDuplicateService;
use App\Services\Contacts\ContactIdentifierService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CMA / deeds capture (phase 1) — the dedicated "Deeds Capture" screen. Lists
 * un-promoted deeds captures (property + owner + owner ID) and promotes one into
 * a real Property + owner Contact link. Deliberately SEPARATE from MIC
 * Opportunities (Johan's directive) — same tracked_properties plumbing, own screen.
 */
final class DeedsCaptureController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if($agencyId === null, 403, 'No agency context.');

        // Data scope (Johan, 2026-08-20): "lots of data now flowing in and staff is getting
        // lost as they are all seeing everything that was scraped." Same None/Own/Branch/All
        // mechanism as Market Intelligence (PermissionService::deedsCaptureScope() reads
        // deeds_capture.view's Role Manager scope, defaults 'own'). Applied to the SAME query
        // that drives both the list AND its count/pagination total, so list and count can
        // never disagree — the exact bug shape MIC and the buyers pipeline shipped twice.
        //
        // On-screen quick filters (Johan, 2026-08-21): "there should always be the scope -
        // admin all, bm branch, agent own... that then needs to be on the screen as well -
        // click own / branch / all quick filters." Role Manager's grant is the CEILING; the
        // ?scope= request is the user's pick WITHIN that ceiling, clamped server-side via the
        // same PermissionService::clampScope() every other screen already uses (Calendar,
        // DealV2, Assistants, BuyersReport, MIC's buyer pipeline) — belt and braces: the
        // button set below is built FROM the ceiling so a wider option never even renders
        // (MIC's stricter pattern, not Buyer Pipeline's looser always-render-All one), AND
        // the server clamps independently, so a hand-crafted ?scope= outside the ceiling can
        // never widen what a request actually returns even with the button absent.
        $deedsScopeCeiling = \App\Services\PermissionService::deedsCaptureScope($user);
        $deedsScope = \App\Services\PermissionService::clampScope($request->input('scope'), $deedsScopeCeiling);
        $deedsScopeOptions = match ($deedsScopeCeiling) {
            'all'    => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            'none'   => [],
            default  => ['own'], // 'own', or unset (null coalesces to 'own' upstream)
        };

        // Agent picker (Johan, 2026-08-20, item 3; confirmed standard, 2026-08-21) — mirrors
        // ContactController::index() exactly: offered ONLY when the ACTIVE scope (post-clamp,
        // not the ceiling) is wide enough to have anyone else to pick — an admin who has
        // clicked down to "Own" has nobody else in view either, until they widen the quick
        // filter back up. 'own' has nobody else, so no picker at all — never offer a choice
        // the backend would then refuse. $agentList() below clamps its OWN candidate set to
        // this SAME active scope, so a picked id can never fall outside what
        // visibleToDeedsCapture() would allow.
        $canPickAgent = in_array($deedsScope, ['all', 'branch'], true);
        $filterAgentId = $request->has('agent_id') ? (string) $request->query('agent_id', '') : '';

        // Search (item 4) — one box, address or contact, both.
        $searchTerm = trim((string) $request->query('search', ''));

        $captures = TrackedProperty::query()
            ->withoutGlobalScopes()
            ->with(['ownerContact', 'owners.contact', 'deedsCapturedBy'])
            ->where('agency_id', $agencyId)
            ->visibleToDeedsCapture($user, $deedsScope)
            ->when($canPickAgent && $filterAgentId === 'unassigned', fn ($q) => $q->whereNull('deeds_captured_by_user_id'))
            ->when($canPickAgent && $filterAgentId !== '' && $filterAgentId !== 'unassigned', fn ($q) => $q->where('deeds_captured_by_user_id', (int) $filterAgentId))
            ->when($searchTerm !== '', fn ($q) => $q->searchDeeds($searchTerm))
            ->whereNull('deleted_at')
            // DEEDS BUG 1 fix (2026-08-19) — surface on the EVENT marker
            // (deeds_captured_at IS NOT NULL), not just the pipeline
            // classification (capture_kind='deeds_capture'). capture_kind is
            // deliberately left alone by a deeds capture that lands on an
            // EXISTING prospecting/P24 lead (so the lead stays in MIC
            // Opportunities), but the capture itself must still show here —
            // otherwise it silently vanishes. Either signal is enough:
            // capture_kind alone still catches historical rows captured
            // before deeds_captured_at existed and never re-captured since.
            ->where(function ($q) {
                $q->where('capture_kind', 'deeds_capture')
                    ->orWhereNotNull('deeds_captured_at');
            })
            ->whereNull('promoted_to_property_id')   // un-promoted only
            // PITCHED-state (Johan 2026-08-14) — this is a SUSPENSE screen, not a deed-import host.
            // Drop deeds already CONSUMED by a worked (PITCHED) item, via EITHER signal:
            //  (a) the deed's owner(s) are now seller(s) on a pitched property (the fundamental
            //      "these people were worked" signal — covers per-owner "+ Link as seller"), or
            //  (b) the deed was explicitly linked to a pitched listing (linked_deed).
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tracked_property_owners as tpo')
                    ->join('contact_property as cp', fn ($j) => $j->on('cp.contact_id', '=', 'tpo.contact_id')->where('cp.role', 'seller'))
                    ->join('prospecting_listings as pl', fn ($j) => $j->on('pl.matched_property_id', '=', 'cp.property_id')->whereNotNull('pl.pitched_at')->whereNull('pl.deleted_at'))
                    ->whereColumn('tpo.tracked_property_id', 'tracked_properties.id')
                    ->whereNotNull('tpo.contact_id');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('prospecting_listings as pl2')
                    ->whereColumn('pl2.linked_deed_tracked_property_id', 'tracked_properties.id')
                    ->whereNotNull('pl2.pitched_at')
                    ->whereNull('pl2.deleted_at');
            })
            ->orderByDesc('last_enriched_at')
            ->paginate(30)
            ->withQueryString();

        // TVA contact captures (2026-08-12) — only ones still carrying at least
        // one un-ingested item; a fully-ingested capture has nothing left for
        // the agent to act on and drops off the screen. Matched ones render
        // under their TrackedProperty card; standalone (no suspense record)
        // render as their own block, headed by name + surname + ID per spec.
        // Same data scope, applied here too (2026-08-20) — a standalone TVA block (matched to
        // no visible TrackedProperty) is still "everything that was scraped" sitting on this
        // exact screen; scoping only $captures and leaving this unrestricted would repeat the
        // list-filters-but-something-else-doesn't bug on the SAME page.
        $tvaCaptures = TvaContactCapture::query()
            ->with(['items' => fn ($q) => $q->whereNull('ingested_at'), 'matchedContact'])
            ->where('agency_id', $agencyId)
            ->visibleToDeedsCapture($user, $deedsScope)
            // Same agent pick + search apply here (item 3/4) — a standalone TVA block is
            // still a scraped record on this screen; leaving it unfiltered while the
            // property list responds to the same controls would be visibly inconsistent.
            ->when($canPickAgent && $filterAgentId === 'unassigned', fn ($q) => $q->whereNull('captured_by_user_id'))
            ->when($canPickAgent && $filterAgentId !== '' && $filterAgentId !== 'unassigned', fn ($q) => $q->where('captured_by_user_id', (int) $filterAgentId))
            ->when($searchTerm !== '', fn ($q) => $q->searchDeeds($searchTerm))
            ->whereHas('items', fn ($q) => $q->whereNull('ingested_at'))
            ->orderByDesc('created_at')
            ->get();

        // ID-number match, computed FRESH at render time (2026-08-13) —
        // deliberately NOT keyed off tracked_property_id (a one-shot snapshot
        // taken at TVA-capture time in TvaContactCaptureController::ingestOne).
        // That snapshot only finds a match if the deeds/CMA capture already
        // existed at the moment the TVA scrape landed — a TVA capture done
        // BEFORE its matching CMA capture (or after the matched property was
        // dismissed/promoted) then NEVER matches, forever, since nothing
        // re-checks it. Re-deriving the match every render against the owner
        // ID numbers on properties currently visible on this screen makes the
        // nesting a live rule ("ID matches an owner → nest here") rather than
        // a point-in-time guess, and naturally self-heals once the missing
        // side of the capture shows up.
        $idNumbers = $tvaCaptures->pluck('id_number')->filter()->unique()->values();
        $ownerMatchesByIdNumber = \App\Models\Prospecting\TrackedPropertyOwner::query()
            ->whereIn('id_number', $idNumbers)
            ->whereIn('tracked_property_id', $captures->pluck('id'))
            ->get(['tracked_property_id', 'id_number'])
            ->groupBy('id_number');

        $tvaByProperty = collect();
        $tvaStandalone = collect();
        foreach ($tvaCaptures as $tvaCapture) {
            $matchedTpIds = $ownerMatchesByIdNumber->get($tvaCapture->id_number, collect())
                ->pluck('tracked_property_id')->unique();
            if ($matchedTpIds->isEmpty()) {
                $tvaStandalone->push($tvaCapture);
                continue;
            }
            foreach ($matchedTpIds as $tpId) {
                $tvaByProperty->put($tpId, $tvaByProperty->get($tpId, collect())->push($tvaCapture));
            }
        }

        // Honest replacement for the removed ComposeSellerService::dismissMatchingTvaCapture()
        // (Johan, 2026-08-22): that method used to answer "has this person already been
        // handled elsewhere?" by silently marking their pending TVA numbers reviewed — which
        // cost 249 real scraped numbers before anyone noticed. The real question re-derives
        // FRESH at render time, the same way $ownerMatchesByIdNumber above does: does a
        // Contact with this exact ID number currently hold an active seller link on ANY
        // property? If so, the card says so — the numbers stay visible and pickable either
        // way; only the explanatory badge changes, never the data.
        $sellerLinkedIdNumbers = Contact::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereIn('id_number', $idNumbers)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('contact_property')
                    ->whereColumn('contact_property.contact_id', 'contacts.id')
                    ->where('contact_property.role', 'seller');
            })
            ->pluck('id_number')
            ->unique();
        foreach ($tvaCaptures as $tvaCapture) {
            $tvaCapture->sellerAlreadyLinked = $tvaCapture->id_number
                && $sellerLinkedIdNumbers->contains($tvaCapture->id_number);
        }

        // 2026-08-19 (Johan, .ai/specs/deeds-capture.md §6 Part B) — "users
        // will see enriched for a current property but wont know what
        // happened to the data. so rather show it in deeds." Show what the
        // MOST RECENT deeds capture did — not the most recent one that
        // happened to change something. Reaching back past a no-op re-capture
        // to an older capture's changes would show a "correction" for a value
        // that has been stable since; if the latest capture changed nothing,
        // the honest card shows nothing (unchanged fields are noise, per
        // Johan — and so is a stale correction from a capture that isn't the
        // one that just ran).
        $fieldChangesByTp = [];
        foreach ($captures as $tp) {
            $chain = $tp->source_chain ?? [];
            $latestDeedsEntry = null;
            foreach ($chain as $entry) {
                if (($entry['type'] ?? null) === 'deeds_capture') {
                    $latestDeedsEntry = $entry; // append-only chain — last match is the most recent
                }
            }
            if ($latestDeedsEntry === null || empty($latestDeedsEntry['field_changes'])) {
                continue;
            }
            $changes = $latestDeedsEntry['field_changes'];
            $fieldChangesByTp[$tp->id] = [
                'date'     => $latestDeedsEntry['date'] ?? null,
                'filled'   => array_values(array_filter($changes, fn ($c) => ($c['change_type'] ?? null) === 'filled')),
                'replaced' => array_values(array_filter($changes, fn ($c) => ($c['change_type'] ?? null) === 'replaced')),
                // 'cleared' (2026-08-19, cc3 — TrackedPropertyMatchOrCreateService::
                // enrich()'s 4th outcome): a stored placeholder (e.g. property_type
                // stuck at the literal "-") got wiped back to null because THIS
                // capture also only had a placeholder to offer. A real, visible
                // change to the record — must not be invisible just because
                // "filled"/"replaced" don't fit it.
                'cleared'  => array_values(array_filter($changes, fn ($c) => ($c['change_type'] ?? null) === 'cleared')),
            ];
        }

        // CX-102 part 2 (2026-08-19, Johan) — "the system must show its
        // working and let the agent overrule it." For every "Already
        // tracked · deed linked" row, the recorded reason for that link
        // (never reconstructed — read straight off the decision written at
        // match time) plus the deed's own source ref, so the view can offer
        // "Not the same property" against the exact capture that caused it.
        $decisionService = app(\App\Services\Prospecting\PropertyMatchDecisionService::class);
        $matchDecisionByTp = [];
        foreach ($captures as $tp) {
            if ($tp->capture_kind === 'deeds_capture') {
                continue; // this capture created its OWN tracked property — nothing to question
            }
            $chain = $tp->source_chain ?? [];
            $latestDeedsRef = null;
            foreach ($chain as $entry) {
                if (($entry['type'] ?? null) === 'deeds_capture' && !empty($entry['ref'])) {
                    $latestDeedsRef = $entry['ref']; // append-only — last one is the most recent
                }
            }
            if ($latestDeedsRef === null) {
                continue;
            }
            $decision = $decisionService->current($agencyId, 'deeds_capture', 'deeds_capture:' . $latestDeedsRef);
            if ($decision !== null && !$decision->isRejected()) {
                $matchDecisionByTp[$tp->id] = $decision;
            }
        }

        // Johan (2026-08-19), after seeing the screen himself: "how does an
        // agent know this is stock or not? Its essentially the same as mic."
        //
        // Every row on THIS screen has promoted_to_property_id NULL by
        // construction (scopeStillEligibleDeedsCapture excludes anything
        // already promoted — once promoted, a TP drops off this list
        // entirely). So "is this already promoted" is never the useful
        // question here — checked anyway, for correctness, but the real
        // parallel to CX-101's MIC question is: "IF this gets promoted,
        // does it merge into EXISTING stock, and is that stock live or
        // stale?" — previewPropertyMatch() runs the exact same erf+suburb /
        // scheme+section / normalised-address rules promoteToStock() itself
        // uses, read-only, so the preview can never disagree with what
        // actually happens when the agent presses Promote.
        $matcher = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class);
        // Deeds-capture duplicate-match take rule (Johan, 2026-08-21) — the SAME flag
        // Johan asked for on 2026-08-19 ("how does an agent know this is stock or
        // not?"), extended with what the agent/BM/admin actually needs to DECIDE:
        // the literal status, exact day count, which date field it came from (and
        // whether it's a fallback), and the resulting band. No second flag.
        $ageResolver = app(\App\Services\Prospecting\PropertyDuplicateAgeResolver::class);
        // Side-by-side comparison panel (Johan, 2026-08-21): "current property... vs
        // new scraped property - showing details side by side... that will allow
        // agent to make simple call right there and then." Built from the SAME
        // evidence PropertyMatchDecisionService records on confirmation, so the panel
        // can never show the agent something different from what gets logged.
        $matchEvidence = app(\App\Services\Prospecting\PropertyDuplicateMatchEvidence::class);
        $stockStatusByTp = [];
        foreach ($captures as $tp) {
            if ($tp->promoted_to_property_id) {
                $property = \App\Models\Property::withoutGlobalScopes()->find($tp->promoted_to_property_id);
                $stockStatusByTp[$tp->id] = $property
                    ? ['state' => $property->isStaleStock() ? 'stale' : 'live', 'property' => $property, 'already' => true, 'age' => null, 'panel' => null]
                    : ['state' => 'unknown', 'property' => null, 'already' => true, 'age' => null, 'panel' => null];
                continue;
            }
            $preview = $matcher->previewPropertyMatch($tp);
            if ($preview) {
                $strategy = $matchEvidence->strategyFor($tp);
                $panelRows = $matchEvidence->panelRows($tp, $preview);
                // 2026-08-22 (matcher-accuracy build) — the actual candidate list, not
                // just a count. Villa Del Sol (8 units, one stand) / Lynne Avenue (6
                // portions, one stand): when more than one exists, list every one so
                // the agent can see and pick, not just a "N possible matches" number
                // with the top one silently pre-selected.
                $candidates = $matchEvidence->candidates($tp, $strategy, (int) $agencyId);
                $stockStatusByTp[$tp->id] = [
                    'state' => $preview->isStaleStock() ? 'stale' : 'live', 'property' => $preview, 'already' => false,
                    'age' => $ageResolver->resolve($preview),
                    'panel' => [
                        'rows' => $panelRows['rows'],
                        'hiddenCount' => $panelRows['hiddenCount'],
                        'strategy' => $strategy,
                        'candidateCount' => $candidates->count(),
                        'candidates' => $candidates,
                        'verdict' => $matchEvidence->verdict($tp, (int) $agencyId),
                    ],
                ];
            } else {
                // 2026-08-22 (matcher-accuracy build, property 15698 gap) —
                // previewPropertyMatch() returning null now covers cases that used to
                // look identical: genuinely nothing found, a structural strategy
                // (sectional/erf/address) that found MORE THAN ONE candidate and
                // deliberately refused to auto-pick one (see
                // TrackedPropertyMatchOrCreateService::resolveOrLogAmbiguous), or a
                // GPS-only signal (never confident alone). All three are visibly
                // different from "nothing at all" — 15698's whole failure was that a
                // near neighbour existed and nothing on screen ever said so.
                $strategy = $matchEvidence->strategyFor($tp);
                $structuralCandidates = $matchEvidence->candidates($tp, $strategy, (int) $agencyId);
                $gpsCandidates = $matchEvidence->candidates($tp, 'gps_proximity', (int) $agencyId);
                $stockStatusByTp[$tp->id] = [
                    'state' => 'not_promoted', 'property' => null, 'already' => false, 'age' => null, 'panel' => null,
                    'ambiguousCandidates' => $structuralCandidates->count() > 1 ? $structuralCandidates : null,
                    'gpsOnlyCandidates' => $gpsCandidates->isNotEmpty() ? $gpsCandidates : null,
                ];
            }
        }

        // Owner-data build part 2 (Johan, 2026-08-19) — an open conflict
        // (TrackedPropertyOwner::isOpenConflict()) is a scraped owner that
        // disagreed with the owner already on file; never auto-resolved
        // (reconcileOwners(), Api\DeedsCaptureController). Grouped by tp so
        // the row can show the current owner and the conflicting one(s)
        // side by side and let the agent decide.
        $openConflictsByTp = [];
        foreach ($captures as $tp) {
            $conflicts = $tp->owners->filter(fn ($o) => $o->isOpenConflict())->values();
            if ($conflicts->isNotEmpty()) {
                $openConflictsByTp[$tp->id] = $conflicts;
            }
        }

        $agentList = $canPickAgent ? $this->deedsAgentList($user, $deedsScope)->values() : collect();
        $selectedAgent = ($canPickAgent && $filterAgentId !== '' && $filterAgentId !== 'unassigned')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        return view('corex.deeds-capture.index', [
            'captures'          => $captures,
            'tvaByProperty'     => $tvaByProperty,
            'tvaStandalone'     => $tvaStandalone,
            'fieldChangesByTp'  => $fieldChangesByTp,
            'matchDecisionByTp' => $matchDecisionByTp,
            'stockStatusByTp'   => $stockStatusByTp,
            'openConflictsByTp' => $openConflictsByTp,
            'canPickAgent'      => $canPickAgent,
            'filterAgentId'     => $filterAgentId,
            'agentList'         => $agentList,
            'selectedAgent'     => $selectedAgent,
            'searchTerm'        => $searchTerm,
            'deedsScope'        => $deedsScope,
            'deedsScopeOptions' => $deedsScopeOptions,
        ]);
    }

    /**
     * Agent picker candidate list (Johan, 2026-08-20, item 3) — mirrors
     * ContactController::agentList() exactly, clamped to the SAME scope ceiling
     * visibleToDeedsCapture() enforces. This is the piece that keeps the picker
     * from ever offering a choice the backend would then refuse — "we hit exactly
     * that trap on the pipeline scope work today" (Johan): 'branch' only lists the
     * user's own branch, 'own' is never called at all ($canPickAgent is false).
     */
    private function deedsAgentList($user, string $scope): \Illuminate\Support\Collection
    {
        $query = \App\Models\User::agencyMembers()->where('is_assistant', false)->where('is_active', 1)->orderBy('name');

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }
        // 'all' — every agency member, no further narrowing.

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * "Not the same property" (CX-102 part 2, 2026-08-19, Johan). An agent
     * looking at an "Already tracked · deed linked" row says the deed does
     * not belong to that property. Breaks the link; the deed's own facts
     * either land on the alternative the agent picked, or become a fresh
     * tracked property of their own — either way the agent's work continues
     * with no dead end. See TrackedPropertyMatchOrCreateService::rejectMatch().
     */
    public function rejectMatch(Request $request, TrackedProperty $trackedProperty, TrackedPropertyMatchOrCreateService $matcher)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if($agencyId === null || (int) $trackedProperty->agency_id !== (int) $agencyId, 404);
        $this->assertPropertyInDeedsScope($user, $trackedProperty);

        $data = $request->validate([
            'source_type'                   => 'required|string|max:50',
            'source_ref'                    => 'required|string|max:200',
            'reason'                        => 'nullable|string|max:500',
            'replacement_tracked_property_id' => 'nullable|integer',
        ]);

        try {
            $result = $matcher->rejectMatch(
                agencyId: $agencyId,
                sourceType: $data['source_type'],
                sourceRef: $data['source_ref'],
                rejectedTrackedPropertyId: (int) $trackedProperty->id,
                byUserId: (int) $user->id,
                reason: $data['reason'] ?? null,
                replacementTrackedPropertyId: isset($data['replacement_tracked_property_id']) ? (int) $data['replacement_tracked_property_id'] : null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // 2026-08-20 — the service (TrackedPropertyMatchOrCreateService::rejectMatch(),
        // qa1-deeds-reject-match-duplicate) no longer creates a second TrackedProperty
        // on reject; it unlinks and $result is either the SAME property (no
        // replacement picked) or the agent's explicitly-picked replacement. Message
        // and redirect updated to match — no second record exists to point at, and
        // Johan's requirement was explicit: "let the user carry on to work with that
        // property," not bounce them to the list. #promote-form-{id} is the same id
        // every card already renders (index.blade.php); a URL fragment scrolls the
        // browser to it with no view/JS change needed.
        return redirect(route('corex.deeds-capture.index') . '#promote-form-' . $result->id)
            ->with('success', 'Marked as not the same property. The wrong match has been unlinked.');
    }

    /**
     * §7.16 (Johan, 2026-08-19) — a scraped owner that conflicted with the
     * owner already on file (TrackedPropertyOwner::isOpenConflict()) sits
     * here until an agent decides which is right. NEVER automated — no
     * "most recent wins", no auto-merge. Two outcomes:
     *
     *   use     — the scraped/conflicting owner was correct. It becomes the
     *             record's owner (owner_contact_id + is_primary); the owner
     *             it replaces is demoted (flagged, never deleted — its own
     *             data is untouched, .ai/specs non-negotiable #1).
     *   dismiss — the owner already on file was correct. The scraped owner's
     *             row stays exactly as captured (never deleted), just marked
     *             resolved so it stops presenting as an open conflict.
     */
    public function resolveOwnerConflict(Request $request, TrackedProperty $trackedProperty, \App\Models\Prospecting\TrackedPropertyOwner $trackedPropertyOwner)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if($agencyId === null || (int) $trackedProperty->agency_id !== (int) $agencyId, 404);
        abort_if((int) $trackedPropertyOwner->tracked_property_id !== (int) $trackedProperty->id, 404);
        $this->assertPropertyInDeedsScope($user, $trackedProperty);

        $data = $request->validate([
            'decision' => 'required|in:use,dismiss',
        ]);

        if (!$trackedPropertyOwner->isOpenConflict()) {
            return back()->with('info', 'That was already resolved.');
        }

        if ($data['decision'] === 'dismiss') {
            $trackedPropertyOwner->conflict_resolved_at = now();
            $trackedPropertyOwner->save();

            return back()->with('success', 'Kept the current owner. The other name is still on file if you need it.');
        }

        \App\Models\Prospecting\TrackedPropertyOwner::where('tracked_property_id', $trackedProperty->id)
            ->whereNull('conflict_flagged_at')
            ->update(['is_primary' => false, 'conflict_flagged_at' => now()]);

        $trackedPropertyOwner->conflict_flagged_at = null;
        $trackedPropertyOwner->conflict_resolved_at = null;
        $trackedPropertyOwner->is_primary = true;
        $trackedPropertyOwner->save();

        $trackedProperty->owner_contact_id = $trackedPropertyOwner->contact_id;
        $trackedProperty->save();

        return back()->with('success', 'Updated the owner to ' . trim((string) $trackedPropertyOwner->name) . '.');
    }

    /**
     * Agent-ticked ingest of TVA-captured phone/email rows into a Contact.
     * Target is either the capture's hard-matched contact (matched_contact_id,
     * from ContactDuplicateService at capture time), an agent-picked EXISTING
     * contact (the DR2 search picker), or a brand-new contact. Never merges,
     * never overwrites an existing contact's identity fields — only ADDS
     * phone/email rows via ContactPhone/ContactEmail + ContactIdentifierService
     * reconcile, same as any other multi-identifier writer.
     */
    public function ingestTva(Request $request, TvaContactCapture $tvaContactCapture, ContactIdentifierService $identifiers)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $tvaContactCapture->agency_id !== (int) $agencyId, 404);
        $this->assertTvaInDeedsScope($user, $tvaContactCapture);

        $data = $request->validate([
            'item_ids'   => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'target'     => 'required|in:matched,existing,new',
            'contact_id' => 'nullable|integer', // required when target=existing
        ]);

        $result = $this->applyTvaItems($tvaContactCapture, $data['item_ids'], $data['target'], $data['contact_id'] ?? null, $user, $agencyId, $identifiers);
        if ($result === null) {
            return back()->with('info', 'Nothing to ingest — those items were already processed.');
        }
        [$contact, $itemsCount] = [$result['contact'], $result['count']];

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', 'Ingested ' . $itemsCount . ' contact value' . ($itemsCount > 1 ? 's' : '') . ' into ' . trim($contact->first_name . ' ' . $contact->last_name) . '.')
            // 2026-08-17 — ingestTva() never got the success_link treatment
            // promote() got on 2026-08-14 (see that method's comment): the
            // flash named the contact but gave the agent nothing to click,
            // so after a successful ingest the browser just sat on the same
            // list screen with no visible change — read by Johan as "the
            // contact details did not load." The contact IS created/updated
            // correctly (verified: exact ticked numbers land on it, nothing
            // else) — this was purely a missing link, not a data bug.
            ->with('success_link', route('corex.contacts.show', $contact->id))
            ->with('success_link_label', 'Open contact →');
    }

    /**
     * Shared by ingestTva() (standalone — the genuinely different
     * enrich-without-promote case, e.g. a TVA scrape for an owner whose
     * property was already promoted) and promote() (the merged one-button
     * case, 2026-08-19 Johan). Applies a TVA capture's ticked item ids onto a
     * target Contact — creates the phone/email rows, discards the
     * still-pending REST of this SAME capture (Johan's one-shot-per-capture
     * rule: "the balance of the numbers can be dumped"), reconciles
     * identifiers. Throws (abort_if 422) when no target contact resolves —
     * inside promote()'s DB::transaction() that rolls back the WHOLE promote,
     * which is the point (a half-succeeded promote is worse than a failed
     * one).
     *
     * @return array{contact: Contact, count: int}|null null = nothing left to ingest (already processed)
     */
    private function applyTvaItems(
        TvaContactCapture $tvaContactCapture,
        array $itemIds,
        string $target,
        ?int $contactId,
        $user,
        int $agencyId,
        ContactIdentifierService $identifiers
    ): ?array {
        $items = $tvaContactCapture->items()
            ->whereIn('id', $itemIds)
            ->whereNull('ingested_at')
            ->get();
        if ($items->isEmpty()) {
            return null;
        }

        $contact = match ($target) {
            'matched' => Contact::withoutGlobalScopes()->find($tvaContactCapture->matched_contact_id),
            'existing' => Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->find($contactId),
            'new' => Contact::create([
                'agency_id'             => $agencyId,
                'branch_id'             => $user->branch_id,
                'first_name'            => $tvaContactCapture->first_name ?: 'Contact',
                'last_name'             => $tvaContactCapture->surname ?? '',
                'phone'                 => '',
                'id_number'             => $tvaContactCapture->id_number,
                'id_number_captured_at' => now(),
                'id_number_source'      => 'tva',
                'created_by_user_id'    => (int) $user->id,
            ]),
        };
        abort_if(!$contact, 422, 'No target contact resolved.');

        $addedPhones = false;
        $addedEmails = false;
        foreach ($items as $item) {
            if ($item->type === 'email') {
                $normalised = strtolower(trim($item->value));
                $exists = $contact->emails()->whereRaw('LOWER(email) = ?', [$normalised])->exists();
                if (!$exists) {
                    $contact->emails()->create([
                        'agency_id' => $agencyId,
                        'email'     => $item->value,
                        'label'     => 'TVA capture' . ($item->link_date ? ' — linked ' . $item->link_date->format('Y-m-d') : ''),
                    ]);
                    $addedEmails = true;
                }
            } else {
                $normalised = app(ContactDuplicateService::class)->normalizePhone($item->value);
                $exists = $normalised && $contact->phones()->where('phone_normalised', $normalised)->exists();
                if (!$exists) {
                    $contact->phones()->create([
                        'agency_id' => $agencyId,
                        'phone'     => $item->value,
                        'label'     => 'TVA capture' . ($item->link_date ? ' — linked ' . $item->link_date->format('Y-m-d') : ''),
                    ]);
                    $addedPhones = true;
                }
            }
            $item->update(['ingested_at' => now(), 'ingested_contact_id' => $contact->id]);
        }

        if ($addedPhones) {
            $identifiers->reconcilePhones($contact->id);
        }
        if ($addedEmails) {
            $identifiers->reconcileEmails($contact->id);
        }

        // Johan: "the balance of the numbers can be dumped — if it's not
        // active we don't need them." Ticking a subset and submitting is a
        // one-shot decision on THIS capture, not a partial one — anything
        // left un-ticked at this point is discarded, not left pending for a
        // future ingest. Discarded == ingested_at set / ingested_contact_id
        // left null, so it drops off index()'s whereNull('ingested_at')
        // pending list exactly like a real ingest does, without a hard
        // delete (non-negotiable #1) — the row (and its value) stays in the
        // table, just marked resolved-without-a-contact.
        $tvaContactCapture->items()
            ->whereNotIn('id', $itemIds)
            ->whereNull('ingested_at')
            ->update(['ingested_at' => now()]);

        return ['contact' => $contact, 'count' => $items->count()];
    }

    /**
     * Data-scope enforcement (Johan, 2026-08-20) — server-side, not just a hidden list row.
     * A route-model-bound TrackedProperty resolves by ID alone, agency check aside; without
     * this, a branch/own-scoped agent could reach another agent's out-of-scope capture just
     * by guessing/editing the URL, even though it never appeared on their list. Called from
     * every write action below, right after the existing agency-mismatch check.
     */
    private function assertPropertyInDeedsScope($user, TrackedProperty $trackedProperty): void
    {
        $scope = \App\Services\PermissionService::deedsCaptureScope($user);
        abort_unless(
            TrackedProperty::query()->withoutGlobalScopes()
                ->visibleToDeedsCapture($user, $scope)
                ->whereKey($trackedProperty->id)
                ->exists(),
            404
        );
    }

    /** Same enforcement as assertPropertyInDeedsScope(), for the standalone TVA-capture actions. */
    private function assertTvaInDeedsScope($user, TvaContactCapture $tvaContactCapture): void
    {
        $scope = \App\Services\PermissionService::deedsCaptureScope($user);
        abort_unless(
            TvaContactCapture::query()->withoutGlobalScopes()
                ->visibleToDeedsCapture($user, $scope)
                ->whereKey($tvaContactCapture->id)
                ->exists(),
            404
        );
    }

    public function promote(Request $request, TrackedProperty $trackedProperty, TrackedPropertyMatchOrCreateService $matcher)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $trackedProperty->agency_id !== (int) $agencyId, 404);
        $this->assertPropertyInDeedsScope($user, $trackedProperty);
        // 2026-08-20 — mirrors the same eligibility test index()'s query and
        // scopeStillEligibleDeedsCapture() already use (and that
        // dismissProperty() was already fixed to stop 404ing on, see that
        // method's own comment): a capture that MATCHED an existing
        // MIC/prospecting lead never gets capture_kind='deeds_capture'
        // stamped (deliberate — see DEEDS BUG 1 fix above), but it is still
        // a real, on-screen, promotable capture. The strict-only check 404'd
        // every such row the instant "Confirm and update" was clicked (live,
        // property #748, capture_kind NULL / deeds_captured_at set).
        abort_if(
            $trackedProperty->capture_kind !== 'deeds_capture' && !$trackedProperty->deeds_captured_at,
            404
        );

        if ($trackedProperty->promoted_to_property_id) {
            return redirect()->route('corex.deeds-capture.index')
                ->with('info', 'This capture was already promoted.');
        }

        // Deeds-specific field mapping (2026-08-12) — promoteToStock()'s own
        // defaults are shared with the general MIC/prospecting promotion path
        // (TrackedPropertyController), so the deeds-only fields (scheme/
        // section, cadastral extent, a real address string) are built HERE,
        // as overrides, rather than changed in the shared method. Was
        // previously passing ONLY title_deed_number, so every other field
        // silently fell through to promoteToStock()'s generic defaults — for
        // a sectional-title deeds capture with no street address, that
        // default composed from street_number/street_name alone, which are
        // never populated by CMA, so it fell back to an effectively-empty
        // address/title.
        // NOTE: 'address' is deliberately NOT set here — PropertyObserver::saving()
        // always recomputes it from unit_number/complex_name/street via
        // composeAddressFromParts() and silently overwrites any value passed to
        // create(), by design (keeps ~4,679 existing rows on one composition
        // rule). Setting complex_name/unit_number below is what actually
        // controls the real address; a redundant 'address' override here would
        // just be discarded and mislead a future reader.

        // Garbage-placeholder guard (2026-08-18) — mirrors index.blade.php's
        // proven $hasRealSection/$isSectional check (2026-08-14, CONFIRMED
        // live on 56 Avenue Svea / 53 Broadway / this capture's own property
        // 6100): the extension sometimes leaks the source page's placeholder
        // LABEL text ("Flat number") into section_number when the field was
        // genuinely empty on a FREEHOLD record — no scheme_name is present,
        // so this is not the complex→freehold state-bleed v3.4.0 fixed
        // (Skippers/Section 3 carrying into an unrelated freehold), it's a
        // separate empty-field-scrapes-as-placeholder defect. A bare
        // truthiness check on section_number mislabels the property as
        // sectional and corrupts unit_number/address with the placeholder
        // string, so every promote-side use of section_number below is
        // gated on $hasRealSection, never raw truthiness.
        $hasRealSection = filled($trackedProperty->section_number) && preg_match('/\d/', (string) $trackedProperty->section_number);
        $hasSectionalSignal = filled($trackedProperty->complex_name)
            || filled($trackedProperty->scheme_name)
            || filled($trackedProperty->scheme_number)
            || $hasRealSection;
        $isSectional = $hasSectionalSignal; // kept as the existing name for the address/unit_number logic below

        // property_type fix (2026-08-19, mapping-proof audit) — CONFIRMED
        // live on real captures (TrackedProperty 11563/9636/9641): the old
        // `$isSectional ? 'Apartment / Flat' : 'House'` binary ALWAYS picked
        // one, even when $hasSectionalSignal was false ONLY because the
        // extension's pre-v3.5.0 stale-residue bug had wiped a genuinely
        // sectional capture's own scheme_name/scheme_number/section_number —
        // TP 11563 (Uvongo Breeze, a real sectional unit) would have promoted
        // as 'House'. A second, independent freehold signal (erf_number) is
        // now required to confidently call it a freehold — and when NEITHER
        // signal is present, or (a page-state anomaly) BOTH are, this no
        // longer guesses: property_type is left as '' (the property edit
        // form's own established "Choose a type…" sentinel — see
        // resources/views/corex/properties/wizard.blade.php:173), which
        // TitleTypeClassifier::fromPropertyType() already handles cleanly
        // (returns null, no crash — verified by reading that class). Per
        // Johan: "a blank an agent can correct, a confidently wrong type
        // they will never notice."
        $hasFreeholdSignal = filled($trackedProperty->erf_number);
        $propertyType = match (true) {
            $hasSectionalSignal && !$hasFreeholdSignal => 'Apartment / Flat',
            $hasFreeholdSignal && !$hasSectionalSignal => 'House',
            default => '', // neither signal, or both (contradictory) — do not guess
        };

        // Title/display-address fix (2026-08-19, mapping-proof audit) —
        // spec §6.3. CONFIRMED live: the old version only ever combined
        // complex_name + "Section N" + suburb, so a FREEHOLD (no
        // complex_name, no section) always collapsed to JUST the suburb
        // ("BEACON ROCKS" for 39 Bairn Street) — the street address was
        // silently dropped on EVERY freehold deeds-capture promotion, and
        // the `?: $trackedProperty->displayAddress()` fallback the old
        // comment relied on never actually fired, because `suburb` alone
        // already made the array_filter()'d string truthy. Rebuilt to match
        // Johan's exact §6.3 formats: freehold "Erf {n}, {street}, {suburb}",
        // sectional "Section {n}, {scheme}, {street}, {suburb}" — both now
        // always include the street line when present.
        $streetLine = trim(($trackedProperty->street_number ?? '') . ' ' . ($trackedProperty->street_name ?? ''));
        $displayAddress = $isSectional
            ? implode(', ', array_filter([
                $hasRealSection ? ('Section ' . $trackedProperty->section_number) : null,
                $trackedProperty->complex_name,
                $streetLine ?: null,
                $trackedProperty->suburb,
            ]))
            : implode(', ', array_filter([
                filled($trackedProperty->erf_number) ? ('Erf ' . $trackedProperty->erf_number) : null,
                $streetLine ?: null,
                $trackedProperty->suburb,
            ]));
        $displayAddress = $displayAddress ?: $trackedProperty->displayAddress();

        // Suburb → town resolution (2026-08-18) — a deeds capture's raw `town`
        // is the district MUNICIPALITY (e.g. "Ray Nkonyeni"), not the actual
        // town an agent/buyer recognises ("Margate"). Resolve the real town +
        // the P24 suburb/city FK chain the rest of the app relies on (map
        // pins, portal sync, matching) via the same p24_suburbs table
        // AppliesP24Location trusts. Soft — never blocks promotion; when the
        // captured suburb text doesn't match a known P24 suburb, the raw town
        // is kept and p24_suburb_mismatch is flagged for the nightly
        // SyncP24Locations/ReconcileP24Suburbs reconcile to pick up later.
        $p24Suburb = $trackedProperty->suburb ? P24Suburb::lookup($trackedProperty->suburb) : null;
        $p24City = $p24Suburb?->city;

        // Extent fix (2026-08-19, mapping-proof audit) — spec §6.4, binding.
        // CONFIRMED live (TrackedProperty 9635, a real sectional unit): the
        // old `'erf_size_m2' => $trackedProperty->cadastral_extent` wrote 70
        // (THE SECTION'S OWN size) into the property's ERF-size column.
        // `cadastral_extent` is NOT an erf size for either title type — it
        // holds a FREEHOLD's own "Cadastral extent" OR a SECTIONAL unit's own
        // "Section extent" (type-dependent single storage slot; see
        // buildDeedsCapturePayload() on the extension side and
        // .ai/specs/deeds-capture.md §6.4's "never merge into one size field"
        // rule) — writing it into erf_size_m2 is exactly the cross-type
        // substitution the spec forbids, for BOTH types. There is no captured
        // field for a freehold's true "Extent" (erf size) yet — §2's payload
        // contract has no slot for it — so erf_size_m2 is deliberately left
        // UNSET below (absent is absent, never substituted) until that lands.
        // size_m2 (the correct sectional unit-size field, per §6.4) now gets
        // the sectional's own extent from that same slot — it was previously
        // wired ONLY to floor_size_m2, a column deeds-capture never
        // populates, so size_m2 was ALWAYS null for every sectional
        // promotion regardless of how good the capture was.
        $overrides = array_filter([
            'title_deed_number' => $trackedProperty->title_deed_number,
            'title'             => $displayAddress,
            'complex_name'      => $trackedProperty->complex_name,   // = CMA scheme name
            'size_m2'           => $isSectional ? $trackedProperty->cadastral_extent : $trackedProperty->floor_size_m2,
            'town'              => $p24City?->name ?? $trackedProperty->town,
            'city'              => $p24City?->name,
            'p24_suburb_id'     => $p24Suburb?->id,
            'p24_city_id'       => $p24Suburb?->p24_city_id,
        ], static fn ($v) => $v !== null && $v !== '');

        // Always-set overrides — must win even when "empty", so
        // promoteToStock()'s own defaults (which re-derive from these SAME
        // raw tracked_property fields) can never reintroduce the garbage
        // these overrides exist to strip. array_filter above would drop a
        // null/false value and let that fallthrough happen.
        $overrides['unit_number']         = $hasRealSection ? $trackedProperty->section_number : null; // = CMA section number, garbage-guarded
        $overrides['property_type']       = $propertyType;
        $overrides['p24_suburb_mismatch'] = $p24City === null;

        // Deeds-capture duplicate-match take rule (Johan, 2026-08-21, revised same
        // day). The decision point is THIS click — not a separate control on the
        // property page. previewPropertyMatch() runs the exact same match
        // promoteToStock() itself will use, read-only, so this can never disagree
        // with what actually happens below.
        //
        // "The match is a suggestion, not a fact" — a proposed match is NEVER
        // applied automatically. The agent confirms SAME or DIFFERENT (two buttons
        // on the row in place of the old single button, whenever a match exists);
        // that confirmation is logged to property_match_decisions (the EXISTING
        // CX-102 "same property?" mechanism, extended — not a second one) BEFORE
        // any rule applies, "regardless of which band the property falls into and
        // regardless of whether the action is then blocked" (Johan) — the
        // rejections are the valuable signal for tuning the matcher later.
        //
        //   DIFFERENT → forceCreate: true below. A brand new property, exactly as
        //               if no match had ever been found. Band rules never apply.
        //   SAME      → gated by the matched property's status:
        //     active / draft         → hard block. Someone is already working it.
        //     0–X days off market    → hard block ("no go").
        //     X–Y days off market    → a PropertyTakeRequest is filed; admin/BM
        //                              decide later. Nothing promoted/reassigned yet.
        //     Y+ days off market     → proceeds below; PropertyDuplicateTakeService
        //                              (inside the transaction) explicitly moves
        //                              status to Prospecting and agent to the
        //                              capturing agent — recorded, never silent.
        //
        // Every hard block below is routed through PropertyDuplicateBlockGuard —
        // ONE authorisation checkpoint, not scattered logic — so a future admin
        // override (Johan has not yet confirmed whether one should exist) is a
        // single function to change.
        $matchPreview = $matcher->previewPropertyMatch($trackedProperty);
        $forceCreate = false;

        if ($matchPreview) {
            $decisionInput = $request->validate([
                'match_decision' => ['required', 'in:same,different'],
                'reject_reason_code' => ['nullable', 'in:' . implode(',', array_keys(\App\Services\Prospecting\PropertyMatchDecisionService::REJECT_REASON_CODES))],
            ]);

            $ageResolver = app(\App\Services\Prospecting\PropertyDuplicateAgeResolver::class);
            $decisionService = app(\App\Services\Prospecting\PropertyMatchDecisionService::class);
            $evidence = app(\App\Services\Prospecting\PropertyDuplicateMatchEvidence::class);

            $subjectKey = 'deeds_capture_property:' . $trackedProperty->id;
            $matchDecision = $decisionService->record(
                agencyId: $agencyId,
                subjectType: 'deeds_capture_property',
                subjectKey: $subjectKey,
                matchedType: 'property',
                matchedId: $matchPreview->id,
                strategy: $evidence->strategyFor($trackedProperty),
                reason: 'Deeds capture matched an existing property on file.',
                incomingFacts: $evidence->comparedValues($trackedProperty, $matchPreview),
            );

            if ($decisionInput['match_decision'] === 'different') {
                $decisionService->reject(
                    $matchDecision,
                    (int) $user->id,
                    reasonCode: $decisionInput['reject_reason_code'] ?? 'other',
                );
                $decisionService->recordOutcome($matchDecision, 'created_new');
                $forceCreate = true;
            } else {
                $decisionService->confirm($matchDecision, (int) $user->id);
                $duplicateAge = $ageResolver->resolve($matchPreview);

                if ($duplicateAge->band === \App\Services\Prospecting\PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED) {
                    $decisionService->recordOutcome($matchDecision, 'blocked');
                    // 2 Venice Drive incident (2026-08-21) — the reason list is the
                    // SAME $duplicateAge->blockReasons the panel shows, so this message
                    // can never disagree with what's on screen, and both reasons show
                    // together when both fire (active AND an unexpired mandate).
                    $reason = 'This matches ' . ($matchPreview->address ?: 'an existing property') . ' — '
                        . implode(', ', $duplicateAge->blockReasons) . '.';
                    return redirect()->route('corex.deeds-capture.index')->with('error', $reason . ' It cannot be updated from Deeds Capture.');
                }

                if ($duplicateAge->band === \App\Services\Prospecting\PropertyDuplicateAgeResult::BAND_NO_GO) {
                    $decisionService->recordOutcome($matchDecision, 'blocked');
                    return redirect()->route('corex.deeds-capture.index')->with('error',
                        'This matches ' . ($matchPreview->address ?: 'an existing property') . ' (' . $matchPreview->statusBadge() . '), off the market for only '
                        . $duplicateAge->days . ' ' . ($duplicateAge->days === 1 ? 'day' : 'days') . ' (' . $duplicateAge->dateFieldLabel() . '). '
                        . 'You cannot take this property yet — wait, or ask an admin/BM to review.'
                    );
                }

                if ($duplicateAge->band === \App\Services\Prospecting\PropertyDuplicateAgeResult::BAND_NEEDS_APPROVAL) {
                    $decisionService->recordOutcome($matchDecision, 'sent_for_approval');
                    $existingRequest = \App\Models\Prospecting\PropertyTakeRequest::where('tracked_property_id', $trackedProperty->id)
                        ->where('status', \App\Models\Prospecting\PropertyTakeRequest::STATUS_PENDING)
                        ->first();
                    if (!$existingRequest) {
                        $existingRequest = \App\Models\Prospecting\PropertyTakeRequest::create([
                            'agency_id'                => $agencyId,
                            'tracked_property_id'      => $trackedProperty->id,
                            'property_id'              => $matchPreview->id,
                            'requested_by_user_id'     => $user->id,
                            'status'                   => \App\Models\Prospecting\PropertyTakeRequest::STATUS_PENDING,
                            'age_days'                 => $duplicateAge->days,
                            'date_field_used'          => $duplicateAge->dateField,
                            'date_is_fallback'         => $duplicateAge->isFallback,
                            'matched_property_status'  => $matchPreview->status,
                        ]);
                        app(\App\Services\Prospecting\PropertyTakeRequestNotifier::class)->notifyApprovers($existingRequest);
                    }
                    return redirect()->route('corex.deeds-capture.index')->with('info',
                        'This matches ' . ($matchPreview->address ?: 'an existing property') . ' (' . $matchPreview->statusBadge() . '), off the market for '
                        . $duplicateAge->days . ' days (' . $duplicateAge->dateFieldLabel() . '). '
                        . 'That needs admin or branch manager approval before it can be taken — they have been notified.'
                    );
                }

                $decisionService->recordOutcome($matchDecision, 'took_existing');
                // BAND_AUTO_TAKE — falls through to the transaction below, where
                // PropertyDuplicateTakeService performs the recorded reassignment.
            }
        }

        // One-button promote+ingest (2026-08-19, Johan, verbatim from last night):
        // "the user should now tick the contact details they want, and clicking
        // the promote to property + contact should be clicked, not click ingest
        // at the bottom... users will instantly get confused if they click
        // promote and it did not capture contact details, and same if they
        // click ingest and the numbers dissapear they immediately will call it
        // broken." Ticked TVA numbers, if any, are keyed by TVA capture id under
        // `tva` — the index.blade.php card's nested TVA blocks submit into THIS
        // same form via the HTML5 form="" attribute rather than their own
        // separate <form>, so one click carries both. Ticking nothing is a
        // legitimate, unwarned path — property + owner only, no numbers.
        $tvaInput = $request->validate([
            'tva'                 => 'nullable|array',
            'tva.*.item_ids'      => 'nullable|array',
            'tva.*.item_ids.*'    => 'integer',
            'tva.*.target'        => 'nullable|in:matched,existing,new',
            'tva.*.contact_id'    => 'nullable|integer',
        ])['tva'] ?? [];

        // Both writes in ONE transaction — both succeed or neither does. A
        // promote that half-works (property created, ticked numbers silently
        // failed) is worse than one that fails outright and can be retried.
        // promoteToStock()'s own DB::transaction() composes cleanly nested
        // inside this one (MySQL savepoints via Laravel's transaction nesting).
        $identifiers = app(ContactIdentifierService::class);
        [$property, $ownerContactIds, $tvaContactsTouched] = DB::transaction(function () use (
            $trackedProperty, $matcher, $overrides, $agencyId, $user, $tvaInput, $identifiers, $matchPreview, $forceCreate
        ) {
            // No "asking price" concept on a deeds capture — Johan (2026-08-18):
            // "we cannot prefill the price - remove that... if anything, save it
            // on the logs or notes but not as the price." Was:
            // 'price' => $trackedProperty->last_known_sold_price, which silently
            // prefilled the SELLING price with a stale HISTORICAL sale price
            // (property 6100: R420,000 from 2008-08-28). Falls through to
            // promoteToStock()'s own 0-default; the sale price is logged as a
            // PropertyNote below instead.
            $property = $matcher->promoteToStock($trackedProperty->id, (int) $user->id, $overrides, $forceCreate);

            // Deeds-capture duplicate-match take rule (Johan, 2026-08-21) — a MATCH
            // onto an existing property that reached this point (not forceCreate'd
            // as "different", and every hard block/approval band already returned
            // above before this transaction opened) is, by construction, in the
            // auto-take band. promoteToStock()'s REFRESH branch deliberately never
            // touches status or agent_id (see TrackedPropertyMatchOrCreateService::
            // REFRESHABLE_PROPERTY_FIELDS) — that's still correct for every OTHER
            // promote path (the generic Tracked Properties button), so it is not
            // changed here. This is the one, explicit, scoped-to-deeds-capture
            // reassignment instead — never silent: "who took it, from whom, when,
            // and which band/date justified it" is recorded on the SAME
            // property_audit_log every other property change uses, not a new
            // mechanism.
            if ($matchPreview && !$forceCreate) {
                $age = app(\App\Services\Prospecting\PropertyDuplicateAgeResolver::class)->resolve($property);
                app(\App\Services\Prospecting\PropertyDuplicateTakeService::class)
                    ->reassign($property, $user, $age);
            }

            if ($trackedProperty->last_known_sold_price) {
                PropertyNote::create([
                    'agency_id'   => $agencyId,
                    'property_id' => $property->id,
                    'user_id'     => $user->id,
                    'content'     => 'Last sale price (deeds history): R'
                        . number_format((float) $trackedProperty->last_known_sold_price, 0, '.', ',')
                        . ($trackedProperty->last_known_sold_date
                            ? ' on ' . Carbon::parse($trackedProperty->last_known_sold_date)->format('Y-m-d')
                            : '')
                        . '. Not the current asking price.',
                ]);
            }

            // Link CURRENT owners as the property's OWNER (contact_property
            // role='owner') — multi-owner support (2026-08-12), extended
            // 2026-08-19 for ownership-history captures (.ai/specs/deeds-capture.md
            // §7.11). Contacts already exist from the CAPTURE step
            // (Api\DeedsCaptureController::ingestOne / captureOwnershipHistory
            // resolve/create them, entity-aware — trust/company registration
            // numbers route to entity_reg_no, never id_number); sequencing per
            // Johan — contact(s) first (already done at capture time), property
            // second (just created above), link last.
            //
            // PAST owners (a prior transfer's seller) are structurally EXCLUDED by
            // this filter, not by a display-layer check somewhere else — a row
            // with ownership_status='past' (or null, an unclassified §7.9 case-4
            // row) never reaches $ownerContactIds, so there is no code path here
            // that could ever link one as this property's owner. Rows from the
            // simple (non-history) capture path all default to
            // ownership_status='current' at the DB level, so this filter is a
            // no-op for every capture that predates §7 — the single-owner path is
            // unchanged.
            $ownerContactIds = $trackedProperty->owners()
                ->where('ownership_status', \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_CURRENT)
                ->pluck('contact_id')->filter()->unique();
            if ($ownerContactIds->isEmpty() && $trackedProperty->owner_contact_id) {
                $ownerContactIds = collect([$trackedProperty->owner_contact_id]);
            }
            foreach ($ownerContactIds as $contactId) {
                DB::table('contact_property')->updateOrInsert(
                    ['contact_id' => $contactId, 'property_id' => $property->id],
                    ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
                );
            }

            // Ticked TVA numbers land on the contact in this SAME transaction.
            // A capture id never present in $tvaInput at all (the agent never
            // touched that block) is left completely untouched — only captures
            // the agent actually ticked into get the "balance can be dumped"
            // one-shot treatment (applyTvaItems()'s discard-the-rest).
            $tvaContactsTouched = [];
            foreach ($tvaInput as $captureId => $captureData) {
                $itemIds = array_filter($captureData['item_ids'] ?? []);
                if ($itemIds === []) {
                    continue; // this block was rendered but nothing was ticked in it
                }
                $capture = TvaContactCapture::where('id', $captureId)->where('agency_id', $agencyId)->first();
                if (!$capture) {
                    continue; // stale/foreign id — never trust the client's capture id blindly
                }
                $result = $this->applyTvaItems(
                    $capture,
                    $itemIds,
                    $captureData['target'] ?? 'new',
                    $captureData['contact_id'] ?? null,
                    $user,
                    $agencyId,
                    $identifiers
                );
                if ($result) {
                    $tvaContactsTouched[] = $result;
                }
            }

            return [$property, $ownerContactIds, $tvaContactsTouched];
        });

        // One outcome, one message — Johan: a user must never wonder "did it
        // also grab the numbers?" after a single click.
        $numbersCount = array_sum(array_column($tvaContactsTouched, 'count'));
        $summary = 'Promoted to a property and linked the owner' . ($ownerContactIds->count() > 1 ? 's' : '') . '.';
        if ($numbersCount > 0) {
            $summary .= ' Ingested ' . $numbersCount . ' contact value' . ($numbersCount > 1 ? 's' : '') . '.';
        }

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', $summary)
            // 2026-08-14 — the flash used to SAY "Open the property to continue"
            // with no actual link, dead-ending the user. success_link is a
            // separate, optional session key (see index.blade.php) so the
            // other flash('success', <plain string>) callers in this
            // controller (dismissProperty/dismissTva) are untouched.
            ->with('success_link', route('corex.properties.show', $property->id));
    }

    /**
     * Remove a deeds-capture property record from the screen (wrong details,
     * duplicate). SOFT delete only (Non-Negotiable #1, no hard deletes) —
     * TrackedProperty already carries SoftDeletes; this just sets
     * deleted_at, which the index() query's whereNull('deleted_at') then
     * excludes. Reversible by an admin (TrackedProperty::withTrashed()
     * ->find($id)->restore()); no in-app restore UI yet, not asked for.
     *
     * 2026-08-19 (Johan — found live-testing, blocked): this used to
     * abort_if($trackedProperty->capture_kind !== 'deeds_capture', 404) —
     * a genuine 404 for every "already tracked" row (capture_kind NULL,
     * matched onto an existing MIC/prospecting lead), which is EXACTLY the
     * category now on screen for every matched capture. Deleting the whole
     * TrackedProperty for that category would be its own bug in the other
     * direction — it has a life outside deeds capture (MIC/prospecting),
     * so a hard soft-delete would wipe out a live lead just because the
     * agent wanted this DEED off the list. Two real cases, two real
     * behaviours, both matching the button's own promise ("no longer show
     * here, but nothing is permanently deleted"):
     *   - capture_kind === 'deeds_capture' (this TP exists only because of
     *     this capture) -> soft-delete the TP, unchanged from before.
     *   - anything else (matched onto an existing record) -> the record
     *     stays; only deeds_captured_at is cleared, which is exactly what
     *     scopeStillEligibleDeedsCapture() checks — the row drops off this
     *     screen, the property/lead itself is untouched, and a fresh
     *     capture (which unconditionally re-stamps deeds_captured_at, see
     *     ingestOne()) makes it reappear as a capture to act on again.
     */
    /**
     * 2 Venice Drive incident (2026-08-21) — Johan: "if 2 venice is blocked then
     * why do I still have the 2 buttons... Same property reads like an action
     * that will do something, when the whole point is that nothing can be
     * done." When a match is hard-blocked (active status, or an unexpired
     * mandate), confirming "same" must never look like it will promote or
     * reassign — because it can't. This RECORDS the confirmation (the matcher
     * was right — valuable signal for tuning it) and clears the row from the
     * queue, exactly like the "Remove" button (dismissProperty() below),
     * because there is genuinely nothing left for the agent to do.
     *
     * Re-resolves the match and band at request time rather than trusting
     * whatever the page showed when it was loaded — if the property changed
     * state in the meantime (e.g. went off market), this refuses rather than
     * silently doing something the agent never actually saw.
     */
    public function acknowledgeBlockedMatch(
        Request $request,
        TrackedProperty $trackedProperty,
        \App\Services\Prospecting\TrackedPropertyMatchOrCreateService $matcher,
        \App\Services\Prospecting\PropertyDuplicateAgeResolver $ageResolver,
        \App\Services\Prospecting\PropertyDuplicateMatchEvidence $evidence,
        \App\Services\Prospecting\PropertyMatchDecisionService $decisionService,
    ) {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $trackedProperty->agency_id !== (int) $agencyId, 404);

        if ($trackedProperty->promoted_to_property_id) {
            return redirect()->route('corex.deeds-capture.index')->with('info', 'This capture was already promoted.');
        }

        $matchPreview = $matcher->previewPropertyMatch($trackedProperty);
        if (!$matchPreview) {
            return redirect()->route('corex.deeds-capture.index')->with('error', 'No match found for this capture — nothing to confirm.');
        }

        $age = $ageResolver->resolve($matchPreview);
        if ($age->band !== \App\Services\Prospecting\PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED) {
            // Its state changed since the page loaded (e.g. it's no longer
            // active) — refuse rather than silently confirming something the
            // agent never actually saw on screen.
            return redirect()->route('corex.deeds-capture.index')
                ->with('error', 'This match is no longer blocked — please refresh and use the Same/Different buttons.');
        }

        $matchDecision = $decisionService->record(
            agencyId: $agencyId,
            subjectType: 'deeds_capture_property',
            subjectKey: 'deeds_capture_property:' . $trackedProperty->id,
            matchedType: 'property',
            matchedId: $matchPreview->id,
            strategy: $evidence->strategyFor($trackedProperty),
            reason: 'Deeds capture matched an existing property, blocked (' . implode(', ', $age->blockReasons) . ').',
            incomingFacts: $evidence->comparedValues($trackedProperty, $matchPreview),
        );
        $decisionService->confirm($matchDecision, (int) $user->id);
        $decisionService->recordOutcome($matchDecision, 'blocked_acknowledged');

        // Clear from the queue — the SAME reversible mechanism "Remove" already
        // uses below, not a new one.
        if ($trackedProperty->capture_kind === 'deeds_capture') {
            $trackedProperty->delete();
        } else {
            $trackedProperty->deeds_captured_at = null;
            $trackedProperty->save();
        }

        $agentName = $matchPreview->agent->name ?? 'the current agent';

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', 'Confirmed — left with ' . $agentName . '. Removed from your queue.');
    }

    public function dismissProperty(Request $request, TrackedProperty $trackedProperty)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $trackedProperty->agency_id !== (int) $agencyId, 404);
        $this->assertPropertyInDeedsScope($user, $trackedProperty);

        if ($trackedProperty->promoted_to_property_id) {
            return redirect()->route('corex.deeds-capture.index')
                ->with('info', 'This capture was already promoted — nothing to remove.');
        }

        if ($trackedProperty->capture_kind === 'deeds_capture') {
            $trackedProperty->delete();
        } else {
            $trackedProperty->deeds_captured_at = null;
            $trackedProperty->save();
        }

        return redirect()->route('corex.deeds-capture.index')->with('success', 'Removed from the list.');
    }

    /**
     * Remove a TVA capture block from the screen (wrong details, duplicate —
     * e.g. an earlier capture superseded by a later one with the correct
     * name). SOFT delete — same reasoning as dismissProperty() above.
     */
    public function dismissTva(Request $request, TvaContactCapture $tvaContactCapture)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $tvaContactCapture->agency_id !== (int) $agencyId, 404);
        $this->assertTvaInDeedsScope($user, $tvaContactCapture);

        $tvaContactCapture->delete();

        return redirect()->route('corex.deeds-capture.index')->with('success', 'Removed from the list.');
    }
}
