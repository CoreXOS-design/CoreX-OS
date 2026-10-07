<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\HandlesLeaseCapture;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\CoreX\LeaseCaptureRequest;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseCaptureService;
use App\Services\Rentals\LeaseRenewalService;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-renewals.md §5/§7/§9 — renewal + one-click outcome
 * actions, reached from the Lease Hub next-step card and header menu.
 * Deliberately a sibling of CoreX\LeaseController (CRUD), not an addition
 * to it — same split rationale as the existing escalate()/activate()/
 * cancel() actions already living on the CRUD controller for plain-lease
 * concerns vs. this controller's renewal-specific concerns.
 *
 * leases.renew already gates every action here (config/corex-permissions.php:138) —
 * no new permission key needed for this stage.
 */
class LeaseRenewalController extends Controller
{
    use AuthorizesRentalRecordScope;
    use HandlesLeaseCapture;
    use ValidatesDocumentUploads;

    /**
     * .ai/specs/leases.md §15.2 / §15.3 / §15.6 (Build L2) — the renewal is the SAME capture screen as a new
     * lease, opened on the lease being renewed: tenants shown read-only (R7), everything else pre-filled from
     * the previous term. The old "Renew or end tenancy" page is retired; its one-click outcomes (month-to-
     * month, notices, reversals) already live in the Lease Hub's own action dialogs and are unchanged.
     */
    public function create(Request $request, Lease $lease): \Illuminate\View\View|RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        // A clean answer, never a 500 from the service: only an active lease can be renewed.
        if ($lease->status !== Lease::STATUS_ACTIVE) {
            return redirect()->route('corex.leases.show', $lease)->withErrors(['lease' => 'Only an active lease can be renewed.']);
        }

        $lease->load(['property', 'tenants.contact']);
        $screen = $this->leaseCaptureScreen($request->user(), $lease);

        return view('corex.leases.capture', array_merge($screen, [
            'mode' => 'renew',
            'lease' => $lease,
            'property' => $lease->property,
            'oldProperty' => null,
            'oldTenants' => [],
            'rentalApplication' => null,
        ]));
    }

    /**
     * The renewal capture screen's POST — "Renew lease only", "Renew lease & prepare for signing" and the
     * signed paper copy (leases.md §15.4, §15.6). The tenants are rebuilt from the lease being renewed by
     * the service; a tenant field posted here is never read (R7).
     */
    public function store(LeaseCaptureRequest $request, Lease $lease): RedirectResponse
    {
        return $this->runLeaseCapture($request, $lease);
    }

    /**
     * §5(a) — "Copy forward": current lease was e-signed through CoreX.
     * Creates the new draft term + a pre-filled e-sign Flow, then redirects
     * the agent straight into the existing wizard (Fill & Review onward).
     */
    public function draftCopyForward(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $terms = $this->validateTerms($request);

        try {
            $result = app(RenewalDraftService::class)->copyForward($lease, $terms, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('docuperfect.esign.step', ['flow' => $result['flow']->id, 'step' => 2])
            ->with('success', 'Renewal draft created — review and send when ready.');
    }

    /**
     * §5(b) — GATE 1: draft fresh from the agency's own mapped lease
     * template. Blocked (422, errors returned to the form) if any required
     * field is still blank on this lease.
     */
    public function draftFromTemplate(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate(['rental_lease_template_id' => ['required', 'integer', 'exists:rental_lease_templates,id']]);
        $terms = $this->validateTerms($request);
        $rentalLeaseTemplate = RentalLeaseTemplate::findOrFail($validated['rental_lease_template_id']);

        try {
            $result = app(RenewalDraftService::class)->draftFromTemplate($lease, $rentalLeaseTemplate, $terms, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('docuperfect.esign.step', ['flow' => $result['flow']->id, 'step' => 2])
            ->with('success', 'Renewal draft created — review and send when ready.');
    }

    /**
     * §5(c) — manual-upload path: agent uploads the signed renewal directly and captures dates/rent by
     * hand. Activates immediately — there is no e-sign cycle to wait for. Since Build L2 this is the capture
     * service's signed-paper-copy path (leases.md §15.6.5) — the same code the capture screen uses; the
     * route stays for the API mirror and older callers.
     */
    public function uploadRenewal(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $terms = $this->validateTerms($request);
        $request->validate(['signed_document' => $this->documentUploadRule(20480)]);

        // Older callers never sent a deposit: keep carrying the previous term's forward, as this route always did.
        $terms['deposit_amount'] = $terms['deposit_amount'] ?? $lease->deposit_amount;
        $terms['signed_document'] = $request->file('signed_document');

        try {
            $result = app(LeaseCaptureService::class)->capture($terms, LeaseCaptureService::INTENT_PAPER_COPY, $request->user(), $lease);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $result)->with('success', 'Renewal recorded and activated.');
    }

    /**
     * rental-command-centre.md §3.1 / leases.md — the agent explicitly
     * cancels a renewal draft instead of sending/activating it. $lease
     * here IS the draft itself — reached from its own Lease Hub page,
     * from the current lease's renew dialog (a link straight to the
     * draft's page), or from the Command Centre row action, all of which
     * point at this same confirmation dialog rather than duplicating it.
     */
    public function cancelDraft(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            app(LeaseRenewalService::class)->cancelRenewalDraft($lease, $validated['cancel_reason'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Renewal draft cancelled.');
    }

    public function monthToMonth(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        app(LeaseRenewalService::class)->recordMonthToMonth($lease, $validated['note'] ?? null, $request->user());

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease set to month-to-month.');
    }

    public function reverseMonthToMonth(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        app(LeaseRenewalService::class)->reverseMonthToMonth($lease, $request->user());

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Month-to-month reversed.');
    }

    public function tenantNotice(Request $request, Lease $lease): RedirectResponse
    {
        return $this->recordNotice($request, $lease, Lease::NOTICE_BY_TENANT);
    }

    public function landlordNotice(Request $request, Lease $lease): RedirectResponse
    {
        return $this->recordNotice($request, $lease, Lease::NOTICE_BY_LANDLORD);
    }

    private function recordNotice(Request $request, Lease $lease, string $givenBy): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate([
            // AT-444 follow-up 2 (2026-10-05) — a bare 'date' rule accepts
            // anything Carbon can parse, including a mistyped 6-digit year
            // (e.g. "202611-03-01") that slips past the dialog's own
            // min/max attributes when typed on a keyboard instead of
            // picked. No agency setting exists for "how far ahead can a
            // move-out date be" (CLAUDE.md non-negotiable: reuse a window
            // setting if one exists, never invent one for this) — 2 years
            // is a fixed, generous sanity bound, not a configurable rule.
            'move_out_date' => ['required', 'date', 'before_or_equal:' . now()->addYears(2)->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
            // .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05:
            // the agent picks exactly one of the three, every time; nothing
            // pre-selected, nothing defaulted server-side either.
            'notice_outcome' => ['required', 'in:' . Lease::NOTICE_OUTCOME_READVERTISE . ',' . Lease::NOTICE_OUTCOME_WITHDRAW . ',' . Lease::NOTICE_OUTCOME_LEAVE],
            'show_available_from_on_portals' => ['nullable', 'boolean'],
        ], [
            'move_out_date.before_or_equal' => 'Move-out date is too far in the future.',
            'notice_outcome.required' => 'Choose what happens to the property.',
        ]);

        // Only meaningful (and only ever rendered by the dialog) for the
        // "readvertise" outcome — has() tells apart "box present and
        // unticked" from "this outcome doesn't render the field at all".
        $showAvailableFromOnPortals = $validated['notice_outcome'] === Lease::NOTICE_OUTCOME_READVERTISE && $request->has('show_available_from_on_portals')
            ? $request->boolean('show_available_from_on_portals')
            : null;

        try {
            app(LeaseRenewalService::class)->recordNotice($lease, $givenBy, $validated['move_out_date'], $validated['note'] ?? null, $request->user(), $validated['notice_outcome'], $showAvailableFromOnPortals);
        } catch (ValidationException $e) {
            // AT-444 follow-up (2026-10-05) — the Lease Hub's notice dialogs
            // reopen with the entered values on error; withInput() is needed
            // here because this is a manually-caught service-level
            // exception, not the auto-flashed $request->validate() path.
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice recorded.');
    }

    /**
     * .ai/specs/rental-renewals.md §19 — lets the agent change the
     * readvertise/withdraw/leave choice later, from the Lease actions menu,
     * while the notice is still active (the move-out date/given-by are not
     * re-entered here, only the outcome).
     */
    public function changeNoticeOutcome(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate([
            'notice_outcome' => ['required', 'in:' . Lease::NOTICE_OUTCOME_READVERTISE . ',' . Lease::NOTICE_OUTCOME_WITHDRAW . ',' . Lease::NOTICE_OUTCOME_LEAVE],
        ], [
            'notice_outcome.required' => 'Choose what happens to the property.',
        ]);

        try {
            app(LeaseRenewalService::class)->changeNoticeOutcome($lease, $validated['notice_outcome'], $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice outcome updated.');
    }

    public function reverseNotice(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        app(LeaseRenewalService::class)->reverseNotice($lease, $request->user());

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice reversed.');
    }

    private function validateTerms(Request $request): array
    {
        return $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'rental_amount' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'is_month_to_month' => ['nullable', 'boolean'],
        ]);
    }
}
