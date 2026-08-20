<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Services\Communications\CommunicationDealLinkingService;
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
    public function __construct(private CommunicationDealLinkingService $linking)
    {
    }

    /**
     * GET /deals-dr2/{deal}/communications/search?q=
     *
     * Deliberately dumb: a plain contains-match on subject/from, agency-scoped,
     * excluding communications already linked (non-trashed) to this deal. No
     * ranking, no suggestion — the agent picks. Building a smarter matcher is
     * the suggestion engine's job, not this one's.
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

        $results = Communication::query()
            ->where('agency_id', $agencyId)
            ->whereNotIn('id', $alreadyLinkedIds)
            ->where(function ($w) use ($q) {
                $w->where('subject', 'like', "%{$q}%")
                    ->orWhere('from_identifier', 'like', "%{$q}%");
            })
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['id', 'channel', 'direction', 'from_identifier', 'subject', 'occurred_at']);

        return response()->json(['data' => $results]);
    }

    /**
     * POST /deals-dr2/{deal}/communications/link
     * body: communication_id
     *
     * Attaches + captures signals, both in one transaction — a link that
     * recorded no signal, or a signal recorded with no link, is a half-done
     * operation either way.
     */
    public function link(Request $request, Deal $deal): JsonResponse
    {
        $validated = $request->validate([
            'communication_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();
        $dealV2Id = $deal->deal_v2_id;

        abort_if($dealV2Id === null, 422, 'This deal has no DR2 twin to link a communication to.');

        $communication = Communication::where('agency_id', $agencyId)
            ->findOrFail($validated['communication_id']);

        $link = $this->linking->link($communication, $dealV2Id, $agencyId, $user);

        return response()->json(['ok' => true, 'link_id' => $link->id], 201);
    }

    /**
     * POST /deals-dr2/{deal}/communications/{link}/unlink
     *
     * Soft-delete only. Does NOT touch communication_learned_refs — the
     * captured signal history is deliberately independent of whether any
     * particular link is currently active, so an unlink (correcting a
     * wrong filing) never erases what was learned from it.
     */
    public function unlink(Request $request, Deal $deal, CommunicationLink $link): JsonResponse
    {
        abort_unless(
            $link->linkable_type === DealV2::class && (int) $link->linkable_id === (int) $deal->deal_v2_id,
            404
        );

        $link->delete();

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
