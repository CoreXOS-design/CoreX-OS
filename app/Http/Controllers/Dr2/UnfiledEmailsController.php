<?php

namespace App\Http\Controllers\Dr2;

use App\Exceptions\Communications\AlreadyFiledException;
use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\Communications\CommunicationDealLinkingService;
use App\Services\Communications\Dr2DealPartyEmailResolver;
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
    public function __construct(
        private CommunicationDealLinkingService $linking,
        private Dr2DealPartyEmailResolver $dealParties,
        private Dr2FilingSuggestionService $filingSuggestions,
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
        $filterAgentId = $request->has('agent_id') ? (string) $request->query('agent_id', '') : '';
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        // CX-113 Phase B — Unfiled (default) / Filed / All. A filed email is already
        // confirmed to a real deal by a human, so the deal-party candidate filter
        // (below) is moot for it and is not re-applied — only the unfiled bucket needs
        // "is this even a candidate" answered.
        $state = $request->input('state', 'unfiled');
        if (! in_array($state, ['unfiled', 'filed', 'all'], true)) {
            $state = 'unfiled';
        }

        $dealPartyEmails = $this->dealParties->partyEmailsForAgency($agencyId);

        $hasActiveDealLink = function ($q) {
            $q->selectRaw('1')->from('communication_links')
                ->whereColumn('communication_links.communication_id', 'communications.id')
                ->where('communication_links.linkable_type', DealV2::class)
                ->whereNull('communication_links.deleted_at');
        };

        $query = Communication::query()
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_EMAIL);

        if ($state === 'unfiled') {
            $query->whereNotExists($hasActiveDealLink)->matchingAnyEmail($dealPartyEmails);
        } elseif ($state === 'filed') {
            $query->whereExists($hasActiveDealLink);
        } else { // all
            $query->where(function ($q) use ($hasActiveDealLink, $dealPartyEmails) {
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
            $q->where('linkable_type', DealV2::class)->whereNull('deleted_at')->with('confirmedBy');
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
     * GET /deals-dr2/unfiled-emails/deal-search?q=
     * Mirrors CommsSuspenseController::dealSearch() (same query shape, same DR1 Deal
     * model) but gated on this screen's own view_deals permission rather than the
     * suspense module's deal_comms_suspense.resolve, so the two pickers don't share a
     * permission dependency across unrelated modules.
     *
     * CX-113 Phase C (Johan, 2026-08-21) — "not just deal number": attorney_name added
     * alongside the existing property_address/deal_no/seller_name/buyer_name so the
     * inline row search can find the deal by any of the same fields Phase B's email
     * search already matches on.
     */
    public function dealSearch(Request $request): JsonResponse
    {
        $user   = $request->user();
        $search = trim((string) $request->input('q', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }

        $deals = Deal::query()->visibleTo($user)
            ->where(function ($q) use ($search) {
                $q->where('property_address', 'like', "%{$search}%")
                    ->orWhere('deal_no', 'like', "%{$search}%")
                    ->orWhere('seller_name', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%")
                    ->orWhere('attorney_name', 'like', "%{$search}%");
            })
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'deal_no', 'property_address', 'seller_name']);

        return response()->json($deals->map(fn ($d) => [
            'id'    => (int) $d->id,
            'label' => trim(($d->deal_no ? "#{$d->deal_no} · " : '') . ($d->property_address ?: '') . ($d->seller_name ? " · {$d->seller_name}" : '')),
        ])->all());
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
        abort_if($deal->deal_v2_id === null, 422, 'This deal has no DR2 twin to link a communication to.');

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
        abort_if($deal->deal_v2_id === null, 422, 'This deal has no DR2 twin to link a communication to.');

        $filed = [];
        foreach ($validated['communication_ids'] as $commId) {
            $communication = Communication::where('agency_id', $agencyId)
                ->where('channel', Communication::CHANNEL_EMAIL)
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('communication_links')
                        ->whereColumn('communication_links.communication_id', 'communications.id')
                        ->where('communication_links.linkable_type', DealV2::class)
                        ->whereNull('communication_links.deleted_at');
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
}
