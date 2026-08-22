<?php

namespace App\Http\Controllers\Dr2;

use App\Exceptions\Communications\AlreadyFiledException;
use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationDr2Dismissal;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\Communications\CommunicationDealLinkingService;
use App\Services\Communications\Dr2DealPartyEmailResolver;
use App\Services\Communications\Dr2EmailDismissalService;
use App\Services\Communications\Dr2FilingSuggestionService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CX-109 (Johan, 2026-08-20) — the Unfiled Emails screen, DR2's primary email-filing
 * workflow. Replaces the "open a deal and search for its emails" direction (CX-108's
 * in-deal tab, kept as a secondary path) with the correct one, Johan's own words:
 * "unfiled email arrives -> agent works through the unfiled pile -> picks the deal it
 * belongs to." Not: open a deal and go fishing.
 *
 * "Unfiled" = an email (channel=email) with no non-trashed CommunicationLink to any
 * DealV2 at all — untouched by manual linking (CX-108/this screen), the AT-231
 * attorney-route auto-file, or anything else. Filing here uses the SAME transaction as
 * CX-108 (CommunicationDealLinkingService::link()) — one linking implementation, not
 * two — and additionally surfaces OTHER unfiled emails whose sender/thread/subject
 * matches what was just learned about the target deal, offered as suggestions the
 * agent must explicitly confirm (never auto-filed).
 *
 * A related-but-different screen already exists: CommsSuspenseController's review
 * queue, which is the AT-231 attorney-route's own narrower auto-suggestion holding
 * area (CommunicationFilingSuspense rows, not general inbound email). Left untouched —
 * whether to eventually consolidate the two is Johan's call, not made here.
 */
class UnfiledEmailsController extends Controller
{
    /**
     * CX-113 Phase I (Johan, 2026-08-22) — "auto matched is based on our calcs if it
     * matches a current deal... if nothing matches confidently, say so honestly rather
     * than showing a weak guess." A single near-conclusive party match (100/95) or a
     * learned-history hit (90) clears it; a diluted frequent-party match (max 25 once a
     * party is on 2+ deals) or a subject/property-only hit (50/20) does not — those stay
     * search-only. Multi-party corroboration (200/300) clears it by a wide margin.
     */
    private const AUTO_MATCH_CONFIDENCE_THRESHOLD = 90;

    public function __construct(
        private CommunicationDealLinkingService $linking,
        private Dr2DealPartyEmailResolver $dealParties,
        private Dr2FilingSuggestionService $filingSuggestions,
        private Dr2EmailDismissalService $dismissals,
    ) {
    }

    /**
     * The working queue — every unfiled email that is BOTH (1) a candidate for filing
     * to a DR2 deal — a party on it (To/From/CC) matches a buyer, seller, or supplier
     * on a DR2-twinned deal — AND (2) one the current user is entitled to see, newest
     * first, searchable.
     *
     * CX-113 Phase A, corrected premise (Johan, 2026-08-21): "we cannot simply pull
     * all emails into unfiled. the emails needs to match on buyer or seller or
     * supplier that are involved in a dr2 deal... all emails are ingested and
     * attached to contact records, thats perfect, but for deal emails we are looking
     * for matches to a deal." Dr2DealPartyEmailResolver resolves the real, current
     * deal-party email set (buyer/seller via deal_contacts, supplier via the SAME
     * columns/tables AT-231's AttorneyCorrespondenceResolver already ships against —
     * deals.attorney_provider_id/bond_originator_provider_id/bond_attorney_provider_id
     * + deal_step_work_orders — never a parallel mechanism). Applied via
     * Communication::scopeMatchingAnyEmail() BEFORE scope, so the two filters compose
     * as a genuine AND, not either alone.
     *
     * Scope resolution (who's ENTITLED to see a deal-party candidate) mirrors Deeds
     * Capture/Market Intelligence exactly (PermissionService::getDataScope +
     * clampScope — decided mechanism, cc5 building Deeds Capture's quick filters
     * against the same one): a role ceiling from Role Manager
     * (dr2_unfiled_emails.view), clamped against the ?scope= request param, rendered
     * as a plain server-side pill toggle that never shows an option past the ceiling
     * (STRICT gating per Johan's instruction — not Buyer Pipeline's looser version).
     * The actual row filter is Communication::scopeVisibleTo() (AT-118/AT-127,
     * already built for the Comms Archive) — own/branch/all PLUS genuine to/from/cc
     * participant matching via participant_identifiers, so "own" truly means "emails
     * this person was on", never a shared-mailbox assumption (HFC has none).
     */
    public function index(Request $request): View
    {
        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();
        $search   = trim((string) $request->input('q', ''));

        $ceiling = PermissionService::dr2UnfiledEmailsScope($user);
        $scope   = PermissionService::clampScope($request->input('scope'), $ceiling);

        $canPickAgent  = in_array($scope, ['branch', 'all'], true);
        $agentList     = $canPickAgent ? $this->agentList($user, $scope) : collect();

        // CX-113 Phase I (Johan, 2026-08-22) — "quite like the click dropdown showing
        // all deals... repurpose that to show My deals for users. That will be a
        // shorter list and make filing easy." Same visibleTo scope + no-twin
        // exclusion as dealSearch()/autoMatchesFor() — never offers a deal filing
        // would then refuse.
        $myDeals = Deal::query()->visibleTo($user)->whereNotNull('deal_v2_id')
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'deal_no', 'property_address'])
            ->map(fn (Deal $d) => [
                'id'    => (int) $d->id,
                'label' => trim(($d->deal_no ? "#{$d->deal_no} · " : '') . ($d->property_address ?: '')),
            ]);
        $filterAgentId = $request->has('agent_id') ? (string) $request->query('agent_id', '') : '';
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        // CX-113 Phase B — Unfiled (default) / Filed / All. A filed email is already
        // confirmed to a real deal by a human, so the deal-party candidate filter
        // (below) is moot for it and is not re-applied — only the unfiled bucket needs
        // "is this even a candidate" answered.
        // CX-113 Phase G — a 4th bucket, "Removed": emails an agent has said are not
        // deal correspondence (Dr2EmailDismissalService). Every other bucket excludes
        // them (below) so a removed email genuinely leaves the working queue; this
        // bucket is the only place they are still findable, with a Restore action.
        $state = $request->input('state', 'unfiled');
        if (! in_array($state, ['unfiled', 'filed', 'all', 'removed'], true)) {
            $state = 'unfiled';
        }

        $dealPartyEmails = $this->dealParties->partyEmailsForAgency($agencyId);

        // CX-113 Phase H (Johan, 2026-08-22) — "no-contact sender" catch. A comm parked
        // by the AT-231 correspondence pipeline (CorrespondenceFilingService::park(),
        // used for a sender with no saved Contact but a recognised attorney/supplier/
        // party) gets a PROVISIONAL DealV2 link — confirmed_at null — for its suggested
        // deal, same as any suggestion. "Filed" must mean a human confirmed it, not
        // merely that a machine guess exists; without whereNotNull('confirmed_at') here
        // every one of those rows was silently mis-classified as already-filed (masked
        // with a blank filer/date, since confirmedBy/confirmed_at were null) and never
        // reached Unfiled at all — confirmed empirically: all 15 real pending-suspense
        // rows on staging already satisfy matchingAnyEmail() below and were only ever
        // hidden by this.
        $hasActiveDealLink = function ($q) {
            $q->selectRaw('1')->from('communication_links')
                ->whereColumn('communication_links.communication_id', 'communications.id')
                ->where('communication_links.linkable_type', DealV2::class)
                ->whereNull('communication_links.deleted_at')
                ->whereNotNull('communication_links.confirmed_at');
        };
        $hasActiveDismissal = function ($q) {
            $q->selectRaw('1')->from('communication_dr2_dismissals')
                ->whereColumn('communication_dr2_dismissals.communication_id', 'communications.id')
                ->whereNull('communication_dr2_dismissals.restored_at');
        };

        $query = Communication::query()
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_EMAIL);

        if ($state === 'removed') {
            $query->whereExists($hasActiveDismissal);
        } elseif ($state === 'unfiled') {
            $query->whereNotExists($hasActiveDismissal)->whereNotExists($hasActiveDealLink)->matchingAnyEmail($dealPartyEmails);
        } elseif ($state === 'filed') {
            $query->whereNotExists($hasActiveDismissal)->whereExists($hasActiveDealLink);
        } else { // all
            $query->whereNotExists($hasActiveDismissal)->where(function ($q) use ($hasActiveDealLink, $dealPartyEmails) {
                $q->whereExists($hasActiveDealLink)
                    ->orWhere(function ($q2) use ($hasActiveDealLink, $dealPartyEmails) {
                        $q2->whereNotExists($hasActiveDealLink)->matchingAnyEmail($dealPartyEmails);
                    });
            });
        }

        // Agent picker only ever offers a candidate already inside $agentList (built
        // scoped to the same $scope ceiling below) — a forged agent_id outside that
        // set is simply ignored, falling back to the full $scope visibility rather
        // than either erroring or accidentally widening access.
        if ($selectedAgent) {
            $query->involvingAgent(User::query()->findOrFail($selectedAgent->id));
        } else {
            $query->visibleTo($user, $scope);
        }

        // CX-113 Phase B — search reaches beyond the email's own text: property
        // address, reference number, seller, buyer, attorney, subject, body, and BOTH
        // sender and recipient addresses. Reference numbers live in free-text
        // subject/body (already covered) rather than a separate indexed field.
        // Realistically indexable today (569 rows): all of it, instantly — nothing
        // here has a real index (LIKE '%term%' can't use one, nor can JSON_CONTAINS on
        // participant_identifiers without a generated column). Fine at this volume;
        // revisit with a generated+indexed participant column or full-text index if
        // the unfiled/filed pool grows into the thousands.
        if (mb_strlen($search) >= 2) {
            $searchDealEmails = $this->dealParties->partyEmailsMatchingDealFields($agencyId, $search);
            $query->where(function ($q) use ($search, $searchDealEmails) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('body_text', 'like', "%{$search}%")
                    ->orWhere('from_identifier', 'like', "%{$search}%")
                    ->orWhereRaw('participant_identifiers LIKE ?', ["%{$search}%"]);
                if (! empty($searchDealEmails)) {
                    $q->orWhere(function ($q2) use ($searchDealEmails) {
                        $q2->matchingAnyEmail($searchDealEmails);
                    });
                }
            });
        }

        $emails = $query->with(['links' => function ($q) {
            // Same confirmed_at fix as $hasActiveDealLink above — a provisional link
            // must never surface as "Filed to X by (blank)" in $filedInfoByCommId below.
            $q->where('linkable_type', DealV2::class)->whereNull('deleted_at')->whereNotNull('confirmed_at')->with('confirmedBy');
        }])->orderByDesc('occurred_at')->paginate(25)->withQueryString();

        // Filed-row display (Phase B: "which deal it is on and who filed it, with a
        // move to another deal action") — resolve DealV2 ids on this page to their DR1
        // labels in one query rather than N+1.
        $dealV2Ids = $emails->getCollection()
            ->flatMap(fn (Communication $c) => $c->links->pluck('linkable_id'))
            ->unique()->values();
        $dealsByV2Id = Deal::query()->whereIn('deal_v2_id', $dealV2Ids)
            ->get(['id', 'deal_v2_id', 'deal_no', 'property_address'])
            ->keyBy('deal_v2_id');

        $filedInfoByCommId = [];
        foreach ($emails->getCollection() as $c) {
            $link = $c->links->first();
            if (! $link) {
                continue;
            }
            $deal = $dealsByV2Id->get($link->linkable_id);
            $filedInfoByCommId[$c->id] = [
                'deal_id'    => $deal?->id,
                'deal_label' => $deal ? trim(($deal->deal_no ? "#{$deal->deal_no} · " : '') . ($deal->property_address ?: '')) : 'Unknown deal',
                'filed_by'   => $link->confirmedBy?->name,
                'filed_at'   => optional($link->confirmed_at)->format('j M Y H:i'),
            ];
        }

        // CX-113 Phase I (Johan, 2026-08-22) — "auto matched" panel, computed
        // server-side (not a per-row fetch — Alpine never boots in this rig's own
        // screenshot verification, and it renders instantly with the page either way).
        // Candidate deals for a given email come from its OWN participants
        // (Dr2DealPartyEmailResolver::dealMatchesForEmails() — the same reverse lookup
        // built for the recipients card) plus Dr2FilingSuggestionService's learned
        // history — deliberately NOT every visible deal; a party-blind text search is
        // what the Search half of the split is for. Scored via the SAME scoreDeal()
        // dealSearch() uses (corroboration included) — one ranking. Only rows not
        // already filed need this — computed for every state but 'removed' (where the
        // whole filing zone is hidden) and 'filed' (already resolved).
        $autoMatchByCommId = [];
        if ($state !== 'removed' && $state !== 'filed') {
            $partyFrequencyForAutoMatch = $this->dealParties->partyDealFrequency($agencyId);
            foreach ($emails->getCollection() as $c) {
                if (isset($filedInfoByCommId[$c->id])) {
                    continue; // already filed — no auto-match zone for this row
                }
                $autoMatchByCommId[$c->id] = $this->autoMatchesFor($c, $user, $agencyId, $partyFrequencyForAutoMatch);
            }
        }

        // CX-113 Phase G — dismissal display for the Removed state (who removed it,
        // why, when) plus the Restore action's target id.
        $dismissedInfoByCommId = [];
        if ($state === 'removed') {
            $dismissals = CommunicationDr2Dismissal::with('dismissedBy')
                ->whereIn('communication_id', $emails->getCollection()->pluck('id'))
                ->whereNull('restored_at')
                ->get()->keyBy('communication_id');
            foreach ($dismissals as $commId => $d) {
                $dismissedInfoByCommId[$commId] = [
                    'reason'       => $d->reasonLabel(),
                    'dismissed_by' => $d->dismissedBy?->name,
                    'dismissed_at' => optional($d->dismissed_at)->format('j M Y H:i'),
                ];
            }
        }

        return view('dr2.unfiled-emails', [
            'emails'            => $emails,
            'search'            => $search,
            'scope'             => $scope,
            'permittedScope'    => $ceiling,
            'canPickAgent'      => $canPickAgent,
            'agentList'         => $agentList,
            'filterAgentId'     => $filterAgentId,
            'selectedAgent'     => $selectedAgent,
            'state'             => $state,
            'filedInfoByCommId' => $filedInfoByCommId,
            'autoMatchByCommId' => $autoMatchByCommId,
            'myDeals'           => $myDeals,
            'dismissedInfoByCommId' => $dismissedInfoByCommId,
            'dismissalReasons'  => CommunicationDr2Dismissal::REASONS,
        ]);
    }

    /**
     * Agent picker candidate list — same decided mechanism as
     * DeedsCaptureController::deedsAgentList(), clamped to the SAME scope ceiling the
     * row query enforces, so the picker can never offer a name the backend would then
     * refuse. Deeds Capture's live version also excludes is_assistant users; that
     * column does not exist yet on this branch's schema (newer live-only work, out of
     * this task's scope) — omitted rather than pulled in unrelated.
     */
    private function agentList(User $user, string $scope): \Illuminate\Support\Collection
    {
        $query = User::agencyMembers()->where('is_active', 1)->orderBy('name');

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * GET /deals-dr2/unfiled-emails/deal-search?q=&communication_id=
     * Mirrors CommsSuspenseController::dealSearch() (same query shape, same DR1 Deal
     * model) but gated on this screen's own view_deals permission rather than the
     * suspense module's deal_comms_suspense.resolve, so the two pickers don't share a
     * permission dependency across unrelated modules.
     *
     * CX-113 Phase C (Johan, 2026-08-21) — "not just deal number": attorney_name added
     * alongside the existing property_address/deal_no/seller_name/buyer_name so the
     * inline row search can find the deal by any of the same fields Phase B's email
     * search already matches on.
     *
     * CX-113 Phase E (Johan, 2026-08-21) — "how do we enhance this so agents don't file
     * to the wrong deal." Real example: searching "santa" returns 5+ deals that all
     * share "Santana" in the address — address text alone doesn't disambiguate. When
     * communication_id is given, every result is scored against THAT email's own
     * signals — email-address match against a NAMED role (near-conclusive), a party
     * surname appearing in the subject, a property-address token match (typo-tolerant:
     * significant address WORDS, not an exact-string compare — "SETION" vs "SECTION"
     * must not sink the match), and Dr2FilingSuggestionService's own learned-history
     * verdict, reused rather than re-derived. Results are returned STRONGEST-FIRST;
     * the agent still clicks — nothing here files anything.
     */
    public function dealSearch(Request $request): JsonResponse
    {
        $user   = $request->user();
        $search = trim((string) $request->input('q', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }

        $agencyId = $user->effectiveAgencyId();
        $communication = null;
        $commId = $request->input('communication_id');
        if ($commId) {
            $communication = Communication::where('agency_id', $agencyId)->find($commId);
        }

        $deals = Deal::query()->visibleTo($user)
            // Johan, 2026-08-22: "DR2 no twin link to link comm to" — an agent picked
            // a real search result and filing refused. Root cause: this query never
            // excluded deals with no DR2 twin (deal_v2_id null — 74 of 154 real deals
            // on staging, not an edge case), so the picker was offering results it
            // could never actually file to. Filing links to the DealV2 row
            // (CommunicationLink::linkable_type = DealV2), so a deal with no twin has
            // nothing to link to — excluded here instead of discovered on click.
            ->whereNotNull('deal_v2_id')
            ->where(function ($q) use ($search) {
                $q->where('property_address', 'like', "%{$search}%")
                    ->orWhere('deal_no', 'like', "%{$search}%")
                    ->orWhere('seller_name', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%")
                    ->orWhere('attorney_name', 'like', "%{$search}%");
            })
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'deal_no', 'property_address', 'seller_name', 'buyer_name', 'attorney_name', 'accepted_status']);

        $historySuggestion = $communication ? $this->filingSuggestions->suggestFor($communication) : null;
        // CX-113 Phase E refinement 2 — computed ONCE per request (agency-wide), not
        // per deal: how many distinct deals each party email appears on. A party on
        // one deal makes an email match near-conclusive; a party on many (the
        // agency's regular bond originator, its usual conveyancer) makes the same
        // match barely worth anything for disambiguation.
        $partyFrequency = $communication ? $this->dealParties->partyDealFrequency($agencyId) : [];

        $results = $deals->map(fn (Deal $d) => $this->scoreDeal($d, $communication, $historySuggestion, $partyFrequency))
            ->sortByDesc('score')->values();

        return response()->json($results->all());
    }

    /**
     * CX-113 Phase E/I — one deal's score + signals against $communication. Shared by
     * dealSearch() (text-search results) and autoMatch()/the server-side "Auto
     * Matched" panel (Phase I, no text query — candidate deals come from the email's
     * own participants) — ONE ranking, never two, so a deal scores identically
     * whether the agent finds it by typing or the system finds it for them.
     */
    private function scoreDeal(Deal $d, ?Communication $communication, ?array $historySuggestion, array $partyFrequency): array
    {
        $signals = $communication ? $this->matchSignalsFor($communication, $d, $historySuggestion, $partyFrequency) : [];
        $score = array_sum(array_column($signals, 'score'));

        // CX-113 Phase J (Johan, 2026-08-22, urgent) — "multi-party corroboration must
        // actually drive the ranking." That already exists for two+ EMAIL-role matches
        // on the same deal (200/300, in matchSignalsFor()). This is the same principle
        // for a DIFFERENT combination: two or more INDEPENDENT signal TYPES agreeing —
        // e.g. a party surname in the subject AND a real (2+-word) property-address
        // match — is genuinely stronger than either alone, the same way it is for party
        // emails. Real case that drove this: deal #1790 (Aloha Park) scored subject=50 +
        // property=20 + status=15 = 85, just under the 90 confidence bar, on an email
        // whose subject and property both genuinely point at it. Skipped when
        // corroboration already fired, to avoid double-stacking two bonuses for the
        // same underlying idea.
        $signalTypes = collect($signals)->pluck('type')->unique();
        if ($signalTypes->count() >= 2 && ! $signalTypes->contains('corroboration')) {
            $score += 10;
        }

        // CX-113 Phase E refinement 1 (Johan, 2026-08-21) — "the same property can
        // have several deals over time... only 1 is proceeding... dr2 status will
        // play an important part." Real staging values: P/G/R/D (Pending/Granted/
        // Registered/Declined — no separate cancelled/collapsed/completed code
        // exists in the data or the model). Proceeding = anything but Declined. A
        // mild ranking NUDGE, not a hard filter or an override of real content
        // signals — declined deals still show, still rank on their own signals,
        // just not ahead of an equally-signalled proceeding one. Status itself is
        // shown on every card regardless (below), not just used for scoring.
        if ($communication && $d->accepted_status !== 'D') {
            $score += 15;
        }

        return [
                'id'              => (int) $d->id,
                'label'           => trim(($d->deal_no ? "#{$d->deal_no} · " : '') . ($d->property_address ?: '')),
                'property_address' => $d->property_address,
                'status'          => $this->dealStatusLabel($d->accepted_status),
                'seller_name'     => $d->seller_name,
                'buyer_name'      => $d->buyer_name,
                'attorney_name'   => $d->attorney_name,
                'signals'         => $signals,
                'score'           => $score,
        ];
    }

    /**
     * CX-113 Phase I — top confident matches for one email, strongest first, capped
     * at 3. Candidate deals come from the email's OWN From/To/Cc participants
     * (Dr2DealPartyEmailResolver::dealMatchesForEmails()) plus the learned-history
     * verdict — never a party-blind scan of every visible deal; that is what Search
     * (the other half of the split) is for. Empty array means "say so honestly", not
     * "show a weak guess" — the view renders that as its own explicit state.
     */
    private function autoMatchesFor(Communication $communication, User $user, int $agencyId, array $partyFrequency): array
    {
        $addresses = collect([$communication->from_identifier])
            ->merge($communication->participant_identifiers ?? [])
            ->filter()->unique()->values()->all();

        $dealMatches = $this->dealParties->dealMatchesForEmails($agencyId, $addresses);
        $candidateDealIds = collect($dealMatches)
            ->flatMap(fn ($matches) => collect($matches)->pluck('deal_id'))
            ->unique();

        $historySuggestion = $this->filingSuggestions->suggestFor($communication);
        if ($historySuggestion) {
            $candidateDealIds->push($historySuggestion['deal_id']);
        }

        // CX-113 Phase J (Johan, 2026-08-22, urgent — real failure): "SECTION 5 ALOHA
        // PARK must find deal #1790." Before this, candidates came ONLY from party-email
        // matches + learned history — a deal with no deal_contacts rows (most of them:
        // only 12 of 80 twinned deals have any, confirmed on real staging data) could
        // never enter the pool no matter how obviously its subject matched. Widened here
        // with a real DB search on the SAME significant words the property-match signal
        // itself requires (2+) to actually count — this only WIDENS who gets considered;
        // scoreDeal()/matchSignalsFor() (Phase J's own stricter 2-word-minimum fix)
        // still decides whether it's confident enough to show, so a coincidental
        // single-word hit still can't surface a wrong deal here either.
        $subjectWords = $this->significantWords((string) $communication->subject);
        if (! empty($subjectWords)) {
            $subjectCandidates = Deal::query()->whereNotNull('deal_v2_id')
                ->where(function ($q) use ($subjectWords) {
                    foreach ($subjectWords as $w) {
                        $q->orWhere('property_address', 'like', "%{$w}%")
                            ->orWhere('seller_name', 'like', "%{$w}%")
                            ->orWhere('buyer_name', 'like', "%{$w}%");
                    }
                })
                ->limit(30)->pluck('id');
            foreach ($subjectCandidates as $id) {
                $candidateDealIds->push($id);
            }
        }

        $candidateDealIds = $candidateDealIds->unique()->values();

        if ($candidateDealIds->isEmpty()) {
            return [];
        }

        $deals = Deal::query()->visibleTo($user)->whereIn('id', $candidateDealIds)
            ->get(['id', 'deal_no', 'property_address', 'seller_name', 'buyer_name', 'attorney_name', 'accepted_status']);

        return $deals->map(fn (Deal $d) => $this->scoreDeal($d, $communication, $historySuggestion, $partyFrequency))
            ->filter(fn ($r) => $r['score'] >= self::AUTO_MATCH_CONFIDENCE_THRESHOLD)
            ->sortByDesc('score')->take(3)->values()->all();
    }

    private function dealStatusLabel(?string $code): string
    {
        return match ($code) {
            'G'     => 'Granted',
            'R'     => 'Registered',
            'D'     => 'Declined',
            default => 'Pending',
        };
    }

    /**
     * The match signals, strongest weight first. Score is deliberately coarse (not a
     * probability) — only the RELATIVE ordering matters, since results are always
     * sorted by it and the agent still picks explicitly.
     *
     * @param  array<string, int>  $partyFrequency  normalised email => distinct deal count
     * @return array<int, array{type: string, label: string, score: int}>
     */
    private function matchSignalsFor(Communication $communication, Deal $deal, ?array $historySuggestion, array $partyFrequency): array
    {
        $signals = [];

        // 1) Email address match — every participant (From, To, AND Cc — the full
        // recipient list, not just the sender) checked against every named role on
        // this deal. Reuses Dr2DealPartyEmailResolver's per-deal role resolution — no
        // separate matcher.
        //
        // MULTI-PARTY CORROBORATION (Johan, 2026-08-22, made twice): "just by having
        // transfer attorney email plus seller email plus buyer email, or a combination
        // of should already give us a hell of a lot of positive linking... a
        // high-frequency attorney alone barely moves it; that same attorney PLUS the
        // seller pins it." ONE matched role is weighted by how DISCRIMINATING that
        // party is (the "koos from ooba" problem — on many deals, barely moves the
        // ranking, unchanged from before). TWO OR MORE distinct matched roles on the
        // SAME email is treated as near-conclusive to certain REGARDLESS of any single
        // party's own frequency — co-occurrence is the signal, not any one address.
        $roleEmails = $this->dealParties->partyEmailsByRoleForDeal($deal->id);
        $roleDisplay = [
            'buyer' => 'buyer', 'seller' => 'seller', 'attorney' => 'attorney',
            'bond_originator' => 'bond originator', 'bond_attorney' => 'bond attorney',
            'coc_supplier' => 'COC supplier', 'other_party' => 'party',
        ];
        // Denormalised on Deal — no per-role name (only these three exist); a matched
        // role without one falls back to the role label alone in the evidence sentence.
        $roleName = [
            'seller' => $deal->seller_name, 'buyer' => $deal->buyer_name, 'attorney' => $deal->attorney_name,
        ];
        $from = strtolower(trim((string) $communication->from_identifier));
        $participants = collect($communication->participant_identifiers ?? [])
            ->map(fn ($e) => strtolower(trim((string) $e)))->all();

        $matches = []; // role => ['email' => ..., 'is_sender' => bool]
        foreach ($roleEmails as $role => $emails) {
            $matchedEmail = null;
            $isSender = false;
            if ($from !== '' && in_array($from, $emails, true)) {
                $matchedEmail = $from;
                $isSender = true;
            } elseif ($hit = array_values(array_intersect($participants, $emails))) {
                $matchedEmail = $hit[0];
            }
            if ($matchedEmail !== null) {
                $matches[$role] = ['email' => $matchedEmail, 'is_sender' => $isSender];
            }
        }

        if (count($matches) >= 2) {
            $evidence = collect($matches)->map(function ($m, $role) use ($roleDisplay, $roleName) {
                $roleLabel = $roleDisplay[$role] ?? 'party';
                $name = $roleName[$role] ?? null;
                return $name ? "{$name} ({$roleLabel})" : "the {$roleLabel}";
            })->values()->implode(', ');
            $n = count($matches);
            $label = "{$n} parties on this email are on this deal — {$evidence}";
            // Fixed, not frequency-weighted (Johan: co-occurrence pins it regardless of
            // any single party's own frequency) — clearly outranks every single-signal
            // score below (max 100) and scales again at 3+ ("certain").
            $score = $n >= 3 ? 300 : 200;
            $signals[] = ['type' => 'corroboration', 'label' => $label, 'score' => $score];
        } elseif (count($matches) === 1) {
            $role = array_key_first($matches);
            $matchedEmail = $matches[$role]['email'];
            $isSender = $matches[$role]['is_sender'];
            $freq = max(1, $partyFrequency[$matchedEmail] ?? 1);
            $roleLabel = $roleDisplay[$role] ?? 'party';
            if ($freq <= 1) {
                $label = ($isSender ? 'Sender is the ' : 'A recipient is the ') . "{$roleLabel} on this deal";
                $score = $isSender ? 100 : 95;
            } else {
                // Quadratic decay — "barely moves the ranking" once a party turns up
                // on more than a couple of deals (freq=2 -> 25, freq=5 -> 4, floored
                // at 5 so it's never literally zero-weight).
                $label = ucfirst($roleLabel) . " on {$freq} deals";
                $score = max(5, (int) round(100 / ($freq ** 2)));
            }
            $signals[] = ['type' => 'email', 'label' => $label, 'score' => $score];
        }

        // 2) Learned filing history — Dr2FilingSuggestionService's own verdict, reused
        // verbatim (never re-derived) when it points at THIS deal.
        //
        // CX-113 Phase J (Johan, 2026-08-22, urgent — real failure): "5 previous emails
        // with this sender were filed to #1791" was a FIXED score of 90 regardless of
        // how many OTHER deals that sender has also been filed to — for
        // linda@vdsatt.co.za (real frequency 9, confirmed via partyDealFrequency) that
        // number is nearly meaningless, yet it alone was enough to cross the confidence
        // bar and produce a false "confident" auto-match (97 Shortens for an Aloha Park
        // email). Weighted the SAME way a single party-email match already is, capped
        // at 60 (max, freq=1) — DELIBERATELY kept below AUTO_MATCH_CONFIDENCE_THRESHOLD
        // (90) so history can NEVER alone produce a confident match, even for a sender
        // who has (so far) only ever been filed to one deal. Johan, explicit: "sender
        // history must not be sufficient alone."
        if ($historySuggestion && (int) $historySuggestion['deal_id'] === (int) $deal->id) {
            $senderFreq = max(1, $partyFrequency[strtolower(trim((string) $communication->from_identifier))] ?? 1);
            $historyScore = max(5, (int) round(60 / ($senderFreq ** 2)));
            $signals[] = ['type' => 'history', 'label' => $historySuggestion['reason'], 'score' => $historyScore];
        }

        // 3) A party's surname appears in the subject — cheap, strong when it hits.
        $subject = (string) $communication->subject;
        foreach (['seller_name' => 'seller', 'buyer_name' => 'buyer', 'attorney_name' => 'attorney'] as $field => $role) {
            $matchedWord = $this->partyNameWordIn($deal->$field, $subject);
            if ($matchedWord !== null) {
                $signals[] = ['type' => 'subject', 'label' => "\"{$matchedWord}\" matches the {$role} on this deal", 'score' => 50];
            }
        }

        // 4) Property address — CX-113 Phase J fix (Johan, 2026-08-22, urgent — real
        // failure): this used to fire on ANY SINGLE significant word matching anywhere
        // in the subject+body, which is how "Property address matches" got asserted for
        // "97 Shortens Country Estate" against an ALOHA PARK email — the single word
        // "Estate" happened to appear in unrelated body text ("AN ESTATE B L KOORSTEN",
        // a deceased-estate reference). Now requires AT LEAST TWO matched significant
        // words (still typo-tolerant at the word level — Johan's own "SETION" example
        // still matches "SECTION" is unaffected since that's partyNameWordIn/subject,
        // not this — but a false single-word coincidence can no longer pass as a match).
        // An address with fewer than 2 significant words simply cannot fire this signal
        // — other signals must do the work instead of a coin-flip on one generic word.
        $matchedPropertyWords = $this->propertyAddressWordsMatched($deal->property_address, $subject . ' ' . (string) $communication->body_text);
        if (count($matchedPropertyWords) >= 2) {
            $signals[] = ['type' => 'property', 'label' => 'Property address matches (' . implode(', ', $matchedPropertyWords) . ')', 'score' => 20];
        }

        return $signals;
    }

    /** First word (3+ chars) of $name found as a case-insensitive substring of $haystack, or null. */
    private function partyNameWordIn(?string $name, string $haystack): ?string
    {
        if (! $name || $haystack === '') {
            return null;
        }
        foreach (preg_split('/\s+/', trim($name)) as $word) {
            $word = trim($word, ".,");
            if (mb_strlen($word) >= 3 && mb_stripos($haystack, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    /**
     * Significant (3+ char, non-structural) lowercased words extracted from $text —
     * shared by the property-address matcher and (Phase J) autoMatchesFor()'s
     * subject-based candidate search, so "what counts as a real word" is defined once.
     */
    private const PROPERTY_MATCH_STOPWORDS = [
        'unit', 'door', 'section', 'sect', 'flat', 'road', 'street', 'ave', 'avenue',
        'drive', 'close', 'complex', 're', 'fwd', 'fw', 'progress', 'report', 'the', 'and',
    ];

    private function significantWords(string $text): array
    {
        preg_match_all('/[a-z0-9]+/i', $text, $m);

        return collect($m[0])
            ->map(fn ($w) => mb_strtolower($w))
            ->filter(fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::PROPERTY_MATCH_STOPWORDS, true))
            ->unique()->values()->all();
    }

    /**
     * CX-113 Phase J fix (Johan, 2026-08-22, urgent — real failure): used to return a
     * bare bool on the FIRST single-word hit — how "97 Shortens Country Estate" falsely
     * matched an Aloha Park email via the one generic word "Estate" appearing,
     * coincidentally, in unrelated body text. Now returns every matched word so the
     * caller can require MORE than one before treating it as a real match, and show
     * honestly which words actually matched.
     *
     * @return array<int, string> matched significant words, lowercased
     */
    private function propertyAddressWordsMatched(?string $address, string $haystack): array
    {
        if (! $address || $haystack === '') {
            return [];
        }
        $addressWords = $this->significantWords($address);
        $haystackLower = mb_strtolower($haystack);

        return array_values(array_filter($addressWords, fn ($w) => mb_stripos($haystackLower, $w) !== false));
    }

    /**
     * GET /deals-dr2/unfiled-emails/{communication}/suggest
     *
     * CX-113 Phase D (Johan, 2026-08-21) — "auto-suggest the deal from filing history...
     * suggest, never auto-file." Fetched when the agent opens a row's inline search box,
     * before they've typed anything. Returns {} (never a 404) when nothing is learned
     * yet for this email — an empty suggestion is a normal, expected outcome, not an
     * error.
     */
    public function suggest(Request $request, Communication $communication): JsonResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();
        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        return response()->json($this->filingSuggestions->suggestFor($communication) ?? []);
    }

    /**
     * POST /deals-dr2/unfiled-emails/{communication}/file
     * body: deal_id, move (optional bool)
     *
     * Files ONE email, then looks for other unfiled emails that share a signal with
     * the deal it was just filed to. Returns them as suggestions — the agent confirms
     * via fileBatch(), nothing here auto-files a second email.
     *
     * CX-113 Phase A — "file once", never a silent second link. If another filer won
     * the race to a DIFFERENT deal first, this returns 409 naming that deal instead of
     * creating a duplicate link; the caller may resubmit with move=true to take it.
     */
    public function file(Request $request, Communication $communication): JsonResponse
    {
        $validated = $request->validate([
            'deal_id' => ['required', 'integer'],
            'move'    => ['sometimes', 'boolean'],
        ]);

        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();

        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        $deal = Deal::query()->visibleTo($user)->findOrFail($validated['deal_id']);
        // Defense-in-depth, not the primary fix (dealSearch() above now excludes these
        // deals from the picker entirely) — a stale client, a forged deal_id, or a
        // future caller could still reach here. Plain language: no internal jargon
        // ("DR2 twin", "deal_v2_id") ever reaches the agent (Johan, 2026-08-22).
        abort_if($deal->deal_v2_id === null, 422, "This deal hasn't been added to the Deal Register yet, so this email can't be filed to it. Pick a different deal.");

        try {
            $link = $this->linking->link($communication, $deal->deal_v2_id, $agencyId, $user, (bool) ($validated['move'] ?? false));
        } catch (AlreadyFiledException $e) {
            $existingDeal = Deal::where('deal_v2_id', $e->existingLink->linkable_id)->first();

            return response()->json([
                'ok'             => false,
                'already_filed'  => true,
                'message'        => $existingDeal
                    ? "Already filed to #{$existingDeal->deal_no} · {$existingDeal->property_address}."
                    : 'Already filed to another deal.',
                'existing_deal'  => $existingDeal ? [
                    'id'    => $existingDeal->id,
                    'label' => trim(($existingDeal->deal_no ? "#{$existingDeal->deal_no} · " : '') . ($existingDeal->property_address ?: '')),
                ] : null,
            ], 409);
        }

        $suggestions = $this->linking
            ->findRelatedUnfiled($agencyId, $deal->deal_v2_id, $communication->id)
            ->map(fn (Communication $c) => [
                'id'      => $c->id,
                'from'    => $c->from_identifier,
                'subject' => $c->subject,
                'when'    => optional($c->occurred_at)->format('j M H:i'),
            ])
            ->values();

        return response()->json([
            'ok'          => true,
            'link_id'     => $link->id,
            'deal'        => [
                'id'    => $deal->id,
                'label' => trim(($deal->deal_no ? "#{$deal->deal_no} · " : '') . ($deal->property_address ?: '')),
            ],
            'suggestions' => $suggestions,
        ], 201);
    }

    /**
     * POST /deals-dr2/unfiled-emails/file-batch
     * body: deal_id, communication_ids[]
     *
     * Files each of the given (still-unfiled) communications to deal_id — the
     * confirm step for suggestions surfaced by file() above. Every communication is
     * independently agency- and unfiled-checked; one bad id in the batch does not
     * abort the rest.
     */
    public function fileBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deal_id'            => ['required', 'integer'],
            'communication_ids'  => ['required', 'array', 'min:1'],
            'communication_ids.*' => ['integer'],
        ]);

        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();

        $deal = Deal::query()->visibleTo($user)->findOrFail($validated['deal_id']);
        // Defense-in-depth, not the primary fix (dealSearch() above now excludes these
        // deals from the picker entirely) — a stale client, a forged deal_id, or a
        // future caller could still reach here. Plain language: no internal jargon
        // ("DR2 twin", "deal_v2_id") ever reaches the agent (Johan, 2026-08-22).
        abort_if($deal->deal_v2_id === null, 422, "This deal hasn't been added to the Deal Register yet, so this email can't be filed to it. Pick a different deal.");

        $filed = [];
        foreach ($validated['communication_ids'] as $commId) {
            $communication = Communication::where('agency_id', $agencyId)
                ->where('channel', Communication::CHANNEL_EMAIL)
                ->whereNotExists(function ($q) {
                    // Same confirmed_at fix as index()'s $hasActiveDealLink — a
                    // provisional (unconfirmed) link must not count as already filed.
                    $q->selectRaw('1')->from('communication_links')
                        ->whereColumn('communication_links.communication_id', 'communications.id')
                        ->where('communication_links.linkable_type', DealV2::class)
                        ->whereNull('communication_links.deleted_at')
                        ->whereNotNull('communication_links.confirmed_at');
                })
                ->find($commId);

            if (! $communication) {
                continue; // already filed elsewhere, or not this agency's — skip, don't abort the batch.
            }

            $link = $this->linking->link($communication, $deal->deal_v2_id, $agencyId, $user);
            $filed[] = $link->id;
        }

        return response()->json(['ok' => true, 'filed_link_ids' => $filed]);
    }

    /**
     * POST /deals-dr2/unfiled-emails/{communication}/dismiss
     * body: reason (one of CommunicationDr2Dismissal::REASONS), reason_other (required
     * when reason=other)
     *
     * CX-113 Phase G — "not deal correspondence" (Johan, 2026-08-22). Agency-wide, same
     * as filing: once dismissed, the row leaves every agent's Unfiled/Filed/All view,
     * not just the dismisser's own. Never touches the Communication row or its contact
     * link — this only decides whether DR2 offers it.
     */
    public function dismiss(Request $request, Communication $communication): JsonResponse
    {
        $validated = $request->validate([
            'reason'       => ['required', 'string', 'in:' . implode(',', array_keys(CommunicationDr2Dismissal::REASONS))],
            'reason_other' => ['required_if:reason,' . CommunicationDr2Dismissal::REASON_OTHER, 'nullable', 'string', 'max:255'],
        ]);

        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        $dismissal = $this->dismissals->dismiss($communication, $user, $validated['reason'], $validated['reason_other'] ?? null);

        return response()->json([
            'ok'     => true,
            'reason' => $dismissal->reasonLabel(),
        ]);
    }

    /** POST /deals-dr2/unfiled-emails/{communication}/restore — puts a dismissed email back. */
    public function restore(Request $request, Communication $communication): JsonResponse
    {
        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        $this->dismissals->restore($communication, $user);

        return response()->json(['ok' => true]);
    }
}
