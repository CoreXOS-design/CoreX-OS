<?php

namespace App\Http\Controllers\Dr2;

use App\Exceptions\Communications\AlreadyFiledException;
use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Services\Communications\CommunicationDealLinkingService;
use App\Services\Communications\Dr2DealPartyEmailResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CX-108 (Johan, 2026-08-20) — manual email-to-deal link/unlink, reachable
 * from DR2. Johan's design: "email arrives in holding area - agent can go
 * and link to a deal." Unlink matters as much as link — agents must be able
 * to correct a wrong filing.
 *
 * No new tables. Both already exist and already fit:
 *   - communication_links (AT-32) — the actual link. link_method='manual'
 *     already defined (CommunicationLink::METHOD_MANUAL). Soft-deleted on
 *     unlink — non-negotiable #1, and it's what keeps the row itself as
 *     history rather than erasing that a link once existed.
 *   - communication_learned_refs (AT-231 P2) — the signal store the (not
 *     yet built) suggestion engine is meant to read. Reused as-is on every
 *     manual link: sender_email, thread_key (when the comm has one), and a
 *     normalised subject_pattern. This does NOT flip is_verified — that
 *     flag drives AT-231's attorney-route silent auto-file, a different,
 *     narrower mechanism (CorrespondenceFilingService::verify()) that this
 *     piece does not touch. Learned rows from a manual DR2 link start
 *     unverified; verifying them is out of scope here.
 *
 * DealV2 (not the DR1 Deal) is the linkable — matches how AT-228 already
 * links comms to DealV2, so this doesn't invent a second convention.
 *
 * 2026-08-20 (CX-109) — the actual link/signal-capture transaction moved to
 * CommunicationDealLinkingService so the Unfiled Emails screen (the primary
 * filing workflow, per Johan's redirect the same day) and this in-deal tab
 * (kept as a secondary path) share ONE linking implementation.
 */
class Dr2CommunicationLinkController extends Controller
{
    public function __construct(
        private CommunicationDealLinkingService $linking,
        private Dr2DealPartyEmailResolver $dealParties,
    ) {
    }

    /**
     * GET /deals-dr2/{deal}/communications/search?q=
     *
     * Agency-scoped, email-only (WhatsApp excluded from DR2 entirely — Johan, "attorney
     * threads span many deals and are unattributable"; this endpoint had NO channel
     * filter at all before CX-113 Phase B, a real pre-existing gap fixed here since this
     * exact method was already being touched for the search broadening), excluding
     * communications already linked (non-trashed) to this deal.
     *
     * CX-113 Phase B (Johan, 2026-08-21) — same broadened search as the Unfiled Emails
     * screen, so an agent learns ONE search box, not two: subject, body, sender AND
     * recipient/CC addresses, plus property address/seller/buyer/attorney (via
     * Dr2DealPartyEmailResolver — same agency-wide resolution, not narrowed to this one
     * deal, since an agent may legitimately want to find and attach an email connected
     * to a DIFFERENT deal's party here too). No ranking, no suggestion — the agent
     * picks. Building a smarter matcher is the suggestion engine's job, not this one's.
     */
    public function search(Request $request, Deal $deal): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $agencyId = $request->user()->effectiveAgencyId();
        $dealV2Id = $deal->deal_v2_id;

        $alreadyLinkedIds = CommunicationLink::query()
            ->where('linkable_type', DealV2::class)
            ->where('linkable_id', $dealV2Id)
            ->pluck('communication_id');

        $searchDealEmails = $this->dealParties->partyEmailsMatchingDealFields($agencyId, $q);

        $results = Communication::query()
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_EMAIL)
            ->whereNotIn('id', $alreadyLinkedIds)
            ->where(function ($w) use ($q, $searchDealEmails) {
                $w->where('subject', 'like', "%{$q}%")
                    ->orWhere('body_text', 'like', "%{$q}%")
                    ->orWhere('from_identifier', 'like', "%{$q}%")
                    ->orWhereRaw('participant_identifiers LIKE ?', ["%{$q}%"]);
                if (! empty($searchDealEmails)) {
                    $w->orWhere(function ($w2) use ($searchDealEmails) {
                        $w2->matchingAnyEmail($searchDealEmails);
                    });
                }
            })
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['id', 'channel', 'direction', 'from_identifier', 'subject', 'occurred_at']);

        return response()->json(['data' => $results]);
    }

    /**
     * POST /deals-dr2/{deal}/communications/link
     * body: communication_id, move (optional bool)
     *
     * Attaches + captures signals, both in one transaction — a link that
     * recorded no signal, or a signal recorded with no link, is a half-done
     * operation either way.
     *
     * CX-113 Phase A — same "file once" guard as the Unfiled Emails screen's
     * file() (Johan: "the two screens should behave the SAME way"). If the
     * search here is stale and the communication was filed elsewhere in the
     * meantime, this refuses with 409 instead of creating a second link.
     */
    public function link(Request $request, Deal $deal): JsonResponse
    {
        $validated = $request->validate([
            'communication_id' => ['required', 'integer'],
            'move'             => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();
        $dealV2Id = $deal->deal_v2_id;

        // Plain language: no internal jargon ("DR2 twin", "deal_v2_id") ever reaches
        // the agent (Johan, 2026-08-22 — same fix as UnfiledEmailsController::file()).
        abort_if($dealV2Id === null, 422, "This deal hasn't been added to the Deal Register yet, so this email can't be filed to it.");

        $communication = Communication::where('agency_id', $agencyId)
            ->findOrFail($validated['communication_id']);

        try {
            $link = $this->linking->link($communication, $dealV2Id, $agencyId, $user, (bool) ($validated['move'] ?? false));
        } catch (AlreadyFiledException $e) {
            $existingDeal = Deal::where('deal_v2_id', $e->existingLink->linkable_id)->first();

            return response()->json([
                'ok'            => false,
                'already_filed' => true,
                'message'       => $existingDeal
                    ? "Already filed to #{$existingDeal->deal_no} · {$existingDeal->property_address}."
                    : 'Already filed to another deal.',
                'existing_deal' => $existingDeal ? [
                    'id'    => $existingDeal->id,
                    'label' => trim(($existingDeal->deal_no ? "#{$existingDeal->deal_no} · " : '') . ($existingDeal->property_address ?: '')),
                ] : null,
            ], 409);
        }

        return response()->json([
            'ok'          => true,
            'link_id'     => $link->id,
            // CX-114 (Johan, 2026-08-22) — "a filing that half-worked must say so".
            // filed/skipped_duplicate/skipped_non_pdf/failed, per
            // CommunicationDealLinkingService::fileAttachments(). The data is no
            // longer silent; whether/how the frontend surfaces it is cc2's call.
            'attachments' => $link->attachment_filing ?? null,
        ], 201);
    }

    /**
     * POST /deals-dr2/{deal}/communications/{link}/unlink
     *
     * Soft-delete only. Does NOT touch communication_learned_refs — the
     * captured signal history is deliberately independent of whether any
     * particular link is currently active, so an unlink (correcting a
     * wrong filing) never erases what was learned from it.
     *
     * CX-114 (Johan, 2026-08-22) — unlinking a filed email now also withdraws
     * (soft-deletes) any documents its attachments filed into this deal's
     * document library, via CommunicationDealLinkingService::unlink(). A
     * document left behind on a deal its email no longer links to is worse than
     * no document at all.
     */
    public function unlink(Request $request, Deal $deal, CommunicationLink $link): JsonResponse
    {
        abort_unless(
            $link->linkable_type === DealV2::class && (int) $link->linkable_id === (int) $deal->deal_v2_id,
            404
        );

        $this->linking->unlink($link);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /deals-dr2/{deal}/communications (linked list, for the tab)
     */
    public function index(Request $request, Deal $deal): JsonResponse
    {
        $dealV2Id = $deal->deal_v2_id;
        if ($dealV2Id === null) {
            return response()->json(['data' => []]);
        }

        $links = CommunicationLink::query()
            ->where('linkable_type', DealV2::class)
            ->where('linkable_id', $dealV2Id)
            ->with(['communication:id,channel,direction,from_identifier,subject,occurred_at', 'confirmedBy:id,name'])
            ->orderByDesc('confirmed_at')
            ->get();

        return response()->json(['data' => $links]);
    }
}
