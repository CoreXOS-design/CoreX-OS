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
    public function __construct(private CommunicationDealLinkingService $linking)
    {
    }

    /** The working queue — every unfiled email, newest first, searchable. */
    public function index(Request $request): View
    {
        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();
        $search   = trim((string) $request->input('q', ''));

        $query = Communication::query()
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_EMAIL)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('communication_links')
                    ->whereColumn('communication_links.communication_id', 'communications.id')
                    ->where('communication_links.linkable_type', DealV2::class)
                    ->whereNull('communication_links.deleted_at');
            });

        if (mb_strlen($search) >= 2) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('from_identifier', 'like', "%{$search}%");
            });
        }

        $emails = $query->orderByDesc('occurred_at')->paginate(25)->withQueryString();

        return view('dr2.unfiled-emails', [
            'emails' => $emails,
            'search' => $search,
        ]);
    }

    /**
     * GET /deals-dr2/unfiled-emails/deal-search?q=
     * Mirrors CommsSuspenseController::dealSearch() (same query shape, same DR1 Deal
     * model) but gated on this screen's own view_deals permission rather than the
     * suspense module's deal_comms_suspense.resolve, so the two pickers don't share a
     * permission dependency across unrelated modules.
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
                    ->orWhere('buyer_name', 'like', "%{$search}%");
            })
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'deal_no', 'property_address', 'seller_name']);

        return response()->json($deals->map(fn ($d) => [
            'id'    => (int) $d->id,
            'label' => trim(($d->deal_no ? "#{$d->deal_no} · " : '') . ($d->property_address ?: '') . ($d->seller_name ? " · {$d->seller_name}" : '')),
        ])->all());
    }

    /**
     * POST /deals-dr2/unfiled-emails/{communication}/file
     * body: deal_id
     *
     * Files ONE email, then looks for other unfiled emails that share a signal with
     * the deal it was just filed to. Returns them as suggestions — the agent confirms
     * via fileBatch(), nothing here auto-files a second email.
     */
    public function file(Request $request, Communication $communication): JsonResponse
    {
        $validated = $request->validate([
            'deal_id' => ['required', 'integer'],
        ]);

        $user     = $request->user();
        $agencyId = $user->effectiveAgencyId();

        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        $deal = Deal::query()->visibleTo($user)->findOrFail($validated['deal_id']);
        abort_if($deal->deal_v2_id === null, 422, 'This deal has no DR2 twin to link a communication to.');

        $link = $this->linking->link($communication, $deal->deal_v2_id, $agencyId, $user);

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
