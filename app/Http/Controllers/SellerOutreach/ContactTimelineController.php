<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Events\SellerOutreach\OutreachOutcomeUpdated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Services\SellerOutreach\SellerOutreachOptOutService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Agent-side timeline of seller outreach for a single contact.
 *
 * Permission: `outreach.compose` (same gate as the composer).
 *
 * Spec: .ai/specs/seller-outreach-spec.md S6.2.
 */
final class ContactTimelineController extends Controller
{
    public function __construct(
        private readonly SellerOutreachOptOutService $optOutService,
    ) {}

    /**
     * Render the timeline tab content.
     *
     * Used by:
     *  - direct page visit (returns the full layout-wrapped view)
     *  - the contact detail page tab include (uses the shared partial via $request->query('partial'))
     */
    public function index(Request $request, Contact $contact)
    {
        $agencyId = $this->assertContactInAgency($request, $contact);
        $data = $this->buildTimelineData($agencyId, $contact);

        $view = $request->wantsJson() || $request->query('partial')
            ? 'seller-outreach.contact-timeline._panel'
            : 'seller-outreach.contact-timeline.index';

        return view($view, $data);
    }

    /**
     * Shared data builder. The contact detail page's main controller
     * (CoreX\ContactController::show) calls this so the embedded tab and
     * the standalone page render from the same source.
     *
     * @return array{contact: Contact, sends: \Illuminate\Database\Eloquent\Collection, clickCounts: Collection, optedOut: bool, outcomeOptions: array<string,string>}
     */
    public function buildTimelineData(int $agencyId, Contact $contact): array
    {
        $sends = SellerOutreachSend::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->with([
                'agent'    => fn ($q) => $q->withoutGlobalScopes(),
                'template' => fn ($q) => $q->withoutGlobalScopes(),
                'property' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->orderByDesc('sent_at')
            ->limit(50)
            ->get();

        // Single grouped query for click counts — avoids N+1.
        $clickCounts = DB::table('seller_outreach_clicks')
            ->where('agency_id', $agencyId)
            ->whereIn('send_id', $sends->pluck('id'))
            ->select('send_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(clicked_at) as last_click_at'))
            ->groupBy('send_id')
            ->get()
            ->keyBy('send_id');

        // AT-50 — derived 3-state communication status + the live transaction(s)
        // that, when present, keep transactional comms flowing despite an opt-out.
        $commMeta = $contact->communicationStatusMeta();
        $liveTransactions = $contact->messaging_opt_out_at !== null
            ? app(\App\Services\SellerOutreach\TransactionStateService::class)->liveTransactions($agencyId, $contact)
            : [];

        return [
            'contact'        => $contact,
            'sends'          => $sends,
            'clickCounts'    => $clickCounts,
            'optedOut'       => $contact->messaging_opt_out_at !== null,
            'optedIn'        => $contact->messaging_opted_in_at !== null,
            'commMeta'         => $commMeta,
            'liveTransactions' => $liveTransactions,
            'outcomeOptions' => $this->outcomeOptions(),
        ];
    }

    public function updateOutcome(Request $request, Contact $contact, SellerOutreachSend $send)
    {
        $agencyId = $this->assertContactInAgency($request, $contact);
        $this->assertSendInContext($send, $agencyId, $contact);

        $validated = $request->validate([
            'outcome'      => 'required|in:' . implode(',', array_keys($this->outcomeOptions())),
            'outcome_note' => 'nullable|string|max:1000',
        ]);

        $previousOutcome = (string) $send->outcome;
        $newOutcome = $validated['outcome'];
        $newNote = $validated['outcome_note'] ?? null;

        if ($newOutcome === $previousOutcome && ($newNote ?? '') === ((string) $send->outcome_note)) {
            return back()->with('status', 'No change.');
        }

        DB::transaction(function () use ($send, $newOutcome, $newNote) {
            $send->update([
                'outcome'                => $newOutcome,
                'outcome_note'           => $newNote,
                'outcome_set_by_user_id' => Auth::id(),
                'outcome_set_at'         => now(),
            ]);
        });

        event(new OutreachOutcomeUpdated(
            send:            $send->fresh(),
            previousOutcome: $previousOutcome,
            newOutcome:      $newOutcome,
            note:            $newNote,
            actorUserId:     Auth::id(),
            agencyId:        $agencyId,
        ));

        return back()->with('status', "Outcome updated to {$this->outcomeOptions()[$newOutcome]}.");
    }

    public function recordOptOut(Request $request, Contact $contact)
    {
        $agencyId = $this->assertContactInAgency($request, $contact);

        $validated = $request->validate([
            'reason'  => 'required|string|max:500',
            'send_id' => 'nullable|integer',
        ]);

        $send = null;
        if (!empty($validated['send_id'])) {
            $send = SellerOutreachSend::withoutGlobalScopes()
                ->where('id', $validated['send_id'])
                ->where('agency_id', $agencyId)
                ->where('contact_id', $contact->id)
                ->first();
        }

        $this->optOutService->recordOptOut(
            agencyId: $agencyId,
            contact:  $contact,
            reason:   $validated['reason'],
            send:     $send,
        );

        return back()->with('status', 'Opt-out recorded. No further pitches will be sent.');
    }

    /**
     * Record an explicit messaging opt-in (e.g. the seller replied YES to a
     * consent request). Mirrors recordOptOut: same agency assertion, same
     * `reason` validation, same permission gate (outreach.compose).
     *
     * Opt-in is a recorded FACT — it does NOT lift an opt-out and does NOT
     * change the send gate.
     */
    public function recordOptIn(Request $request, Contact $contact)
    {
        $agencyId = $this->assertContactInAgency($request, $contact);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $contact->recordOptIn($validated['reason'], (int) Auth::id());

        return back()->with('status', 'Opt-in recorded. The seller has confirmed consent to receive messages.');
    }

    public function outcomeOptions(): array
    {
        return [
            SellerOutreachSend::OUTCOME_REPLIED        => 'Seller replied',
            SellerOutreachSend::OUTCOME_BOOKED         => 'Appointment booked',
            SellerOutreachSend::OUTCOME_NO_RESPONSE    => 'No response',
            SellerOutreachSend::OUTCOME_NOT_INTERESTED => 'Not interested',
            SellerOutreachSend::OUTCOME_BOUNCED        => 'Bounced / invalid',
            SellerOutreachSend::OUTCOME_SENT           => 'Sent (reset)',
            SellerOutreachSend::OUTCOME_CLICKED        => 'Clicked (revert)',
        ];
    }

    private function assertContactInAgency(Request $request, Contact $contact): int
    {
        $user = $request->user();
        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($agencyId === null, 403, 'Super_admin without an agency context cannot view outreach timelines.');
        if ((int) $contact->agency_id !== (int) $agencyId) {
            abort(404);
        }
        return (int) $agencyId;
    }

    private function assertSendInContext(SellerOutreachSend $send, int $agencyId, Contact $contact): void
    {
        if ((int) $send->agency_id !== $agencyId) {
            abort(404);
        }
        if ((int) $send->contact_id !== (int) $contact->id) {
            abort(404);
        }
    }
}
