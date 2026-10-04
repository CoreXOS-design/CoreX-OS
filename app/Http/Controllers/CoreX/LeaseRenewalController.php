<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseRenewalService;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    use ValidatesDocumentUploads;

    /**
     * .ai/specs/rental-renewals.md §4-§9 — the "one small screen" the agent
     * uses to enter the new term and either send a renewal draft, upload a
     * signed renewal, or record a one-click outcome. §5(b)'s eligibility
     * preview (missing fields against the lease's OWN current data, before
     * the agent has typed new terms) lets the agent see which templates are
     * ready without yet submitting — the real check re-runs against the
     * actually-submitted terms in draftFromTemplate() below.
     */
    public function create(Request $request, Lease $lease): \Illuminate\View\View
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $draftService = app(RenewalDraftService::class);
        $leaseTemplates = RentalLeaseTemplate::active()->with('template')->orderBy('name')->get()
            ->map(fn (RentalLeaseTemplate $t) => [
                'template' => $t,
                'missing' => $draftService->missingRequiredFields($lease, $t, [], $request->user()),
            ]);

        return view('corex.leases.renewal', [
            'lease' => $lease,
            'canCopyForward' => $lease->source === 'esign_document' && (bool) $lease->source_document_id,
            'leaseTemplates' => $leaseTemplates,
            'tenantNoticePeriodDays' => \App\Models\LeaseSetting::tenantNoticePeriodDaysFor($lease->agency_id),
            'autoReadvertiseOnNotice' => \App\Models\LeaseSetting::autoReadvertiseOnNoticeFor($lease->agency_id),
        ]);
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
     * §5(c) — manual-upload path: agent uploads the signed renewal directly
     * and captures dates/rent/escalation by hand. Activates immediately —
     * there is no e-sign cycle to wait for (§6 only applies to the e-sign
     * paths).
     */
    public function uploadRenewal(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $terms = $this->validateTerms($request);
        $request->validate(['signed_document' => $this->documentUploadRule(20480)]);

        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($lease, $terms, $request, $user) {
                $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($lease, $terms, $user);

                $file = $request->file('signed_document');
                $ext = $file->getClientOriginalExtension();
                $path = $file->storeAs(
                    'lease-renewals/' . $newTerm->id,
                    Str::uuid() . ($ext ? ".{$ext}" : ''),
                    'local'
                );

                $document = Document::create([
                    'original_name' => $file->getClientOriginalName(),
                    'storage_path' => $path,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'document_type_id' => \App\Models\DocumentType::where('slug', 'lease_agreement')->value('id'),
                    'source_type' => 'lease',
                    'source_id' => $newTerm->id,
                    'uploaded_by' => $user->id,
                ]);
                if ($newTerm->property_id) {
                    $document->properties()->attach($newTerm->property_id);
                }

                event(new \App\Events\Document\DocumentUploaded(document: $document, owner: $newTerm, actorUserId: $user->id));

                return app(LeaseRenewalService::class)->activateRenewalTerm($newTerm, $user);
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $result)->with('success', 'Renewal recorded and activated.');
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
            'move_out_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // .ai/specs/rental-renewals.md §15 (GATE 2) row 2 — the dialog's own
        // tick, defaulting from the agency setting when the form didn't
        // render a value at all (never when the box was actually unticked —
        // the view always renders this checkbox, so has() tells apart "box
        // absent from this request" from "box present and unticked").
        $readvertise = $request->has('readvertise')
            ? $request->boolean('readvertise')
            : \App\Models\LeaseSetting::autoReadvertiseOnNoticeFor($lease->agency_id);

        try {
            app(LeaseRenewalService::class)->recordNotice($lease, $givenBy, $validated['move_out_date'], $validated['note'] ?? null, $request->user(), $readvertise);
        } catch (ValidationException $e) {
            // AT-444 follow-up (2026-10-05) — the Lease Hub's notice dialogs
            // reopen with the entered values on error; withInput() is needed
            // here because this is a manually-caught service-level
            // exception, not the auto-flashed $request->validate() path.
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice recorded.');
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
