<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesLeaseAccess;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Lease;
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
    use AuthorizesLeaseAccess;
    use ValidatesDocumentUploads;

    /**
     * .ai/specs/rental-renewals.md §4-§9 — the "one small screen" the agent
     * uses to enter the new term and either send a renewal draft, upload a
     * signed renewal, or record a one-click outcome. Eligibility for the
     * copy-forward path (§5(a)) is a simple source check — the template-
     * draft path (§5(b)) is NOT offered here yet (item 5's own WAIT gate).
     */
    public function create(Request $request, Lease $lease): \Illuminate\View\View
    {
        $this->guardLease($lease);

        return view('corex.leases.renewal', [
            'lease' => $lease,
            'canCopyForward' => $lease->source === 'esign_document' && (bool) $lease->source_document_id,
            'tenantNoticePeriodDays' => \App\Models\LeaseSetting::tenantNoticePeriodDaysFor($lease->agency_id),
        ]);
    }

    /**
     * §5(a) — "Copy forward": current lease was e-signed through CoreX.
     * Creates the new draft term + a pre-filled e-sign Flow, then redirects
     * the agent straight into the existing wizard (Fill & Review onward).
     */
    public function draftCopyForward(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardLease($lease);

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
     * §5(c) — manual-upload path: agent uploads the signed renewal directly
     * and captures dates/rent/escalation by hand. Activates immediately —
     * there is no e-sign cycle to wait for (§6 only applies to the e-sign
     * paths).
     */
    public function uploadRenewal(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardLease($lease);

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
        $this->guardLease($lease);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        app(LeaseRenewalService::class)->recordMonthToMonth($lease, $validated['note'] ?? null, $request->user());

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease set to month-to-month.');
    }

    public function reverseMonthToMonth(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardLease($lease);

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
        $this->guardLease($lease);
        $validated = $request->validate([
            'move_out_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(LeaseRenewalService::class)->recordNotice($lease, $givenBy, $validated['move_out_date'], $validated['note'] ?? null, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice recorded.');
    }

    public function reverseNotice(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardLease($lease);

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
