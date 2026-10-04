<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseRenewalService;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $user = $request->user();

        try {
            $activated = DB::transaction(function () use ($lease, $terms, $request, $user) {
                $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($lease, $terms, $user);

                $file = $request->file('signed_document');
                $ext = $file->getClientOriginalExtension();
                $path = $file->storeAs('lease-renewals/' . $newTerm->id, Str::uuid() . ($ext ? ".{$ext}" : ''), 'local');

                $document = Document::create([
                    'original_name' => $file->getClientOriginalName(),
                    'storage_path' => $path,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'document_type_id' => DocumentType::where('slug', 'lease_agreement')->value('id'),
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
            'readvertise' => ['nullable', 'boolean'],
        ]);

        $readvertise = $request->has('readvertise')
            ? $request->boolean('readvertise')
            : \App\Models\LeaseSetting::autoReadvertiseOnNoticeFor($lease->agency_id);

        try {
            $updated = app(LeaseRenewalService::class)->recordNotice($lease, $givenBy, $validated['move_out_date'], $validated['note'] ?? null, $request->user(), $readvertise);
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
