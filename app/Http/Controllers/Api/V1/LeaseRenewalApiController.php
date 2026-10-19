<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseNotice;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseCaptureService;
use App\Services\Rentals\LeaseNoticeService;
use App\Services\Rentals\LeaseRenewalService;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-renewals.md §10 — JSON mirror of CoreX\LeaseRenewalController's
 * web actions, same scope guard, for Andre's mobile app. Same services, same
 * validation — this controller only differs in response shape.
 */
class LeaseRenewalApiController extends Controller
{
    use AuthorizesRentalRecordScope;
    use ValidatesDocumentUploads;

    public function draftCopyForward(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $terms = $this->validateTerms($request);

        try {
            $result = app(RenewalDraftService::class)->copyForward($lease, $terms, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json([
            'lease' => $result['lease'],
            'flow_id' => $result['flow']->id,
        ]);
    }

    /** §5(b) — GATE 1: draft fresh from the agency's own mapped lease template. */
    public function draftFromTemplate(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate(['rental_lease_template_id' => ['required', 'integer', \App\Rules\FoundInAgency::in('rental_lease_templates')]]);
        $terms = $this->validateTerms($request);
        $rentalLeaseTemplate = RentalLeaseTemplate::findOrFail($validated['rental_lease_template_id']);

        try {
            $result = app(RenewalDraftService::class)->draftFromTemplate($lease, $rentalLeaseTemplate, $terms, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json([
            'lease' => $result['lease'],
            'flow_id' => $result['flow']->id,
        ]);
    }

    public function uploadRenewal(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $terms = $this->validateTerms($request);
        $request->validate(['signed_document' => $this->documentUploadRule(20480)]);

        // Build L2 (leases.md §15.6.5): the capture service's signed-paper-copy path — the same code the capture
        // screen uses. Older callers never sent a deposit; the previous term's is carried forward as before.
        $terms['deposit_amount'] = $terms['deposit_amount'] ?? $lease->deposit_amount;
        $terms['signed_document'] = $request->file('signed_document');

        try {
            $activated = app(LeaseCaptureService::class)->capture($terms, LeaseCaptureService::INTENT_PAPER_COPY, $request->user(), $lease);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['lease' => $activated]);
    }

    public function monthToMonth(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $updated = app(LeaseRenewalService::class)->recordMonthToMonth($lease, $validated['note'] ?? null, $request->user());

        return response()->json(['lease' => $updated]);
    }

    public function reverseMonthToMonth(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $updated = app(LeaseRenewalService::class)->reverseMonthToMonth($lease, $request->user());

        return response()->json(['lease' => $updated]);
    }

    public function tenantNotice(Request $request, Lease $lease): JsonResponse
    {
        return $this->recordNotice($request, $lease, Lease::NOTICE_BY_TENANT);
    }

    public function landlordNotice(Request $request, Lease $lease): JsonResponse
    {
        return $this->recordNotice($request, $lease, Lease::NOTICE_BY_LANDLORD);
    }

    /**
     * lease-notices.md (audit 2026-10-18, A-M8): same as the web action - a notice given by the tenant or the landlord is NOT written
     * onto the lease here. It becomes a case waiting for the agent's review (LeaseNoticeService::give, VIA_AGENT); the move-out date and the
     * readvertise/withdraw/leave outcome are set only when someone with rental_notices.review accepts it.
     */
    private function recordNotice(Request $request, Lease $lease, string $givenBy): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        // Backward compatible with the mobile app's original payload (move_out_date / note / notice_outcome): the old names are mapped
        // to the new ones, notice_date defaults to today, and notice_outcome is accepted but ignored - the reviewer chooses the outcome.
        $request->merge(array_filter([
            'intended_end_date' => $request->input('intended_end_date', $request->input('move_out_date')),
            'reason' => $request->input('reason', $request->input('note')),
            'notice_date' => $request->input('notice_date', now()->toDateString()),
        ], fn ($v) => $v !== null));

        $request->validate([
            'notice_outcome' => ['nullable', 'in:' . Lease::NOTICE_OUTCOME_READVERTISE . ',' . Lease::NOTICE_OUTCOME_WITHDRAW . ',' . Lease::NOTICE_OUTCOME_LEAVE],
            'show_available_from_on_portals' => ['nullable', 'boolean'],
        ]);
        // audit 2026-10-19 (A17/A28): the same entry rules as the web dialogs and the notice-cases route
        $validated = app(LeaseNoticeService::class)->validateAgentEntry($request->all(), $request->file('attachment'));

        try {
            $notice = app(LeaseNoticeService::class)->give($lease, $givenBy, $validated, LeaseNotice::VIA_AGENT, null, $request->user(), $request->file('attachment'));
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['notice' => $notice, 'lease' => $lease->fresh()]);
    }

    /** .ai/specs/rental-renewals.md §19 — JSON mirror of the web action. */
    public function changeNoticeOutcome(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate([
            'notice_outcome' => ['required', 'in:' . Lease::NOTICE_OUTCOME_READVERTISE . ',' . Lease::NOTICE_OUTCOME_WITHDRAW . ',' . Lease::NOTICE_OUTCOME_LEAVE],
        ]);

        try {
            $updated = app(LeaseRenewalService::class)->changeNoticeOutcome($lease, $validated['notice_outcome'], $request->user());
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['lease' => $updated]);
    }

    public function reverseNotice(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $updated = app(LeaseRenewalService::class)->reverseNotice($lease, $request->user());

        return response()->json(['lease' => $updated]);
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
