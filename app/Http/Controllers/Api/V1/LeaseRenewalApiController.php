<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseCaptureService;
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
        $validated = $request->validate(['rental_lease_template_id' => ['required', 'integer', 'exists:rental_lease_templates,id']]);
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

    private function recordNotice(Request $request, Lease $lease, string $givenBy): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $validated = $request->validate([
            'move_out_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            // .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05:
            // the agent picks exactly one of the three, every time; no
            // default, same contract as the web form.
            'notice_outcome' => ['required', 'in:' . Lease::NOTICE_OUTCOME_READVERTISE . ',' . Lease::NOTICE_OUTCOME_WITHDRAW . ',' . Lease::NOTICE_OUTCOME_LEAVE],
            'show_available_from_on_portals' => ['nullable', 'boolean'],
        ]);

        $showAvailableFromOnPortals = $validated['notice_outcome'] === Lease::NOTICE_OUTCOME_READVERTISE && $request->has('show_available_from_on_portals')
            ? $request->boolean('show_available_from_on_portals')
            : null;

        try {
            $updated = app(LeaseRenewalService::class)->recordNotice($lease, $givenBy, $validated['move_out_date'], $validated['note'] ?? null, $request->user(), $validated['notice_outcome'], $showAvailableFromOnPortals);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['lease' => $updated]);
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
