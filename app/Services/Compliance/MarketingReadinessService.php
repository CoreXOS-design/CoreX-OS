<?php

namespace App\Services\Compliance;

use App\Models\DevSetting;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MarketingReadinessService
{
    public function __construct(
        private AgencyComplianceDocTypeService $docTypes = new AgencyComplianceDocTypeService(),
    ) {
    }

    /**
     * Evaluate all marketing readiness gates for a property.
     */
    public function statusFor(Property $property): ReadinessReport
    {
        // Dev override: compliance checks globally disabled — treat as ready
        if (DevSetting::bool('compliance_checks_disabled')) {
            return new ReadinessReport(
                ready: true,
                snapshotAt: $property->compliance_snapshot_at,
                blockedBy: [],
                nextActions: [],
                checklist: [
                    'dev_override' => ['passed' => true, 'label' => 'Dev override', 'detail' => 'Compliance checks disabled in Dev Settings'],
                ],
            );
        }

        // Short-circuit: if snapshot exists, property is already cleared
        if ($property->compliance_snapshot_at !== null) {
            return new ReadinessReport(
                ready: true,
                snapshotAt: $property->compliance_snapshot_at,
                blockedBy: [],
                nextActions: [],
                checklist: $this->buildHistoricalChecklist($property),
            );
        }

        $checklist = [];
        $blockedBy = [];
        $nextActions = [];

        // ── Document gates: the agency's CONFIGURABLE required document types ──
        // Each required type must have at least one typed, non-soft-deleted
        // Drive document present on the property or a seller contact. Presence
        // IS the gate — no approval status is checked (doctrine: a wet-ink doc
        // is physically signed off by the BM before upload; the system-side
        // approval control lives only in the untouched e-sign pipeline).
        $driveTab = route('corex.properties.show', $property->id) . '?tab=drive';
        foreach ($this->requiredDocTypeGates($property) as $slug => $gate) {
            $checklist[$slug] = $gate;
            if (!$gate['passed']) {
                $blockedBy[] = $gate['detail'];
                $nextActions[] = [
                    'label' => $gate['action_label'] ?? ('Upload ' . $gate['label']),
                    'action_url' => $gate['action_url'] ?? $driveTab,
                ];
            }
        }

        // Gate: Listing has photos (>= 4)
        $checklist['photos'] = $this->checkPhotos($property);
        if (!$checklist['photos']['passed']) {
            $blockedBy[] = $checklist['photos']['detail'];
            $nextActions[] = [
                'label' => 'Upload at least 4 property photos',
                'action_url' => $checklist['photos']['action_url'],
            ];
        }

        // Gate: Listing details complete
        $checklist['details_complete'] = $this->checkDetailsComplete($property);
        if (!$checklist['details_complete']['passed']) {
            $blockedBy[] = $checklist['details_complete']['detail'];
            $nextActions[] = [
                'label' => 'Complete missing property details',
                'action_url' => $checklist['details_complete']['action_url'],
            ];
        }

        return new ReadinessReport(
            ready: empty($blockedBy),
            snapshotAt: null,
            blockedBy: $blockedBy,
            nextActions: $nextActions,
            checklist: $checklist,
        );
    }

    /**
     * Take a compliance snapshot — freezes the "ready" state on the property.
     * Throws MarketingBlockedException if not ready.
     */
    public function snapshotCompliance(Property $property, User $by): void
    {
        $report = $this->statusFor($property);

        if (!$report->ready) {
            throw new MarketingBlockedException($report);
        }

        // Build snapshot data
        $sellers = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get();

        $sellerData = $sellers->map(fn ($c) => [
            'contact_id' => $c->id,
            'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
            'role' => $c->pivot->role,
            'fica_status' => DB::table('fica_submissions')
                ->where('contact_id', $c->id)
                ->orderByDesc('id')
                ->value('status'),
        ])->values()->toArray();

        // Capture the typed Drive documents that satisfied each required type
        // at go-live — the permanent record of what was on file.
        $presentDocs = $this->presentRequiredDocuments($property);

        $snapshotData = [
            'snapshot_version' => 2,
            'snapshotted_by_user_id' => $by->id,
            'snapshotted_by_name' => $by->name,
            'sellers' => $sellerData,
            'documents' => $presentDocs,
            'listing' => [
                'title' => $property->title,
                'price' => $property->price,
                'property_type' => $property->property_type,
                'photo_count' => $this->countPhotos($property),
            ],
            'checklist' => $report->checklist,
        ];

        $property->update([
            'compliance_snapshot_at' => now(),
            'compliance_snapshot_data' => $snapshotData,
            'first_marketed_at' => $property->first_marketed_at ?? now(),
        ]);
    }

    /**
     * Quick check: is property marketable (ready OR already snapshotted)?
     */
    public function isMarketable(Property $property): bool
    {
        if (DevSetting::bool('compliance_checks_disabled')) {
            return true;
        }

        if ($property->compliance_snapshot_at !== null) {
            return true;
        }

        return $this->statusFor($property)->ready;
    }

    // ── Document gates (agency-configurable required types) ──

    /**
     * Build a checklist entry for every document type the agency requires.
     * Keyed by slug so the readiness panel renders each by its own label.
     *
     * @return array<string, array{passed:bool,label:string,detail:string,action_label:?string,action_url:string}>
     */
    private function requiredDocTypeGates(Property $property): array
    {
        $agencyId = (int) $property->agency_id;
        $requiredTypes = $agencyId > 0
            ? $this->docTypes->requiredTypesFor($agencyId)
            : collect();

        if ($requiredTypes->isEmpty()) {
            return [];
        }

        $sellerIds = $this->sellerContactIds($property);
        $presentTypeIds = $this->presentDriveTypeIds($property, $sellerIds);
        $ficaSlug = config('corex-compliance.fica_slug', 'fica');
        $driveTab = route('corex.properties.show', $property->id) . '?tab=drive';

        $gates = [];
        foreach ($requiredTypes as $type) {
            $present = $presentTypeIds->contains($type->id);
            $detail = $type->label . ' on file';

            // FICA bridge: also satisfied by the authoritative FICA workflow
            // (all sellers approved) so the existing FICA path never regresses.
            if (!$present && $type->slug === $ficaSlug) {
                [$ficaPass, $ficaDetail] = $this->checkSellersFicaSubmissions($property, $sellerIds);
                $present = $ficaPass;
                if (!$present) {
                    $detail = $ficaDetail;
                }
            }

            if (!$present && $type->slug !== $ficaSlug) {
                $detail = 'Missing: ' . $type->label . ' — upload the signed document to the property Drive';
            }

            $gates[$type->slug] = [
                'passed' => $present,
                'label' => $type->label,
                'detail' => $detail,
                'action_label' => $present ? null : 'Upload ' . $type->label,
                'action_url' => $driveTab,
            ];
        }

        return $gates;
    }

    /** Seller/owner/landlord/lessor contact ids linked to the property. */
    private function sellerContactIds(Property $property): \Illuminate\Support\Collection
    {
        return $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->pluck('contacts.id');
    }

    /**
     * Distinct document_type_ids present (not soft-deleted) on the property
     * Drive or on any seller contact's Drive.
     */
    private function presentDriveTypeIds(Property $property, \Illuminate\Support\Collection $sellerIds): \Illuminate\Support\Collection
    {
        $propertyTypeIds = DB::table('document_properties as dp')
            ->join('documents as d', 'd.id', '=', 'dp.document_id')
            ->where('dp.property_id', $property->id)
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.document_type_id')
            ->pluck('d.document_type_id');

        $contactTypeIds = collect();
        if ($sellerIds->isNotEmpty()) {
            $contactTypeIds = DB::table('document_contacts as dc')
                ->join('documents as d', 'd.id', '=', 'dc.document_id')
                ->whereIn('dc.contact_id', $sellerIds)
                ->whereNull('d.deleted_at')
                ->whereNotNull('d.document_type_id')
                ->pluck('d.document_type_id');
        }

        return $propertyTypeIds->merge($contactTypeIds)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Authoritative FICA check on fica_submissions: every seller approved.
     * @return array{0:bool,1:string}
     */
    private function checkSellersFicaSubmissions(Property $property, \Illuminate\Support\Collection $sellerIds): array
    {
        if ($sellerIds->isEmpty()) {
            return [false, 'No sellers linked to property'];
        }

        $approvedIds = DB::table('fica_submissions')
            ->whereIn('contact_id', $sellerIds)
            ->where('status', 'approved')
            ->pluck('contact_id')
            ->unique();

        $missing = $sellerIds->reject(fn ($id) => $approvedIds->contains($id));

        if ($missing->isEmpty()) {
            return [true, 'All sellers FICA approved'];
        }

        $names = DB::table('contacts')
            ->whereIn('id', $missing)
            ->get(['first_name', 'last_name'])
            ->map(fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')))
            ->filter()
            ->implode(', ');

        return [false, 'FICA outstanding — no approved FICA or FICA document on Drive for: ' . ($names ?: 'seller(s)')];
    }

    /**
     * The typed Drive documents currently satisfying each required type —
     * recorded into the compliance snapshot at go-live.
     */
    private function presentRequiredDocuments(Property $property): array
    {
        $agencyId = (int) $property->agency_id;
        $requiredTypes = $agencyId > 0 ? $this->docTypes->requiredTypesFor($agencyId) : collect();
        if ($requiredTypes->isEmpty()) {
            return [];
        }

        $sellerIds = $this->sellerContactIds($property);
        $typeIds = $requiredTypes->pluck('id')->all();

        $propertyDocs = DB::table('document_properties as dp')
            ->join('documents as d', 'd.id', '=', 'dp.document_id')
            ->where('dp.property_id', $property->id)
            ->whereIn('d.document_type_id', $typeIds)
            ->whereNull('d.deleted_at')
            ->get(['d.id', 'd.document_type_id', 'd.original_name', 'd.source_type']);

        $contactDocs = collect();
        if ($sellerIds->isNotEmpty()) {
            $contactDocs = DB::table('document_contacts as dc')
                ->join('documents as d', 'd.id', '=', 'dc.document_id')
                ->whereIn('dc.contact_id', $sellerIds)
                ->whereIn('d.document_type_id', $typeIds)
                ->whereNull('d.deleted_at')
                ->get(['d.id', 'd.document_type_id', 'd.original_name', 'd.source_type']);
        }

        $labelById = $requiredTypes->keyBy('id');

        return $propertyDocs->merge($contactDocs)->unique('id')->map(fn ($d) => [
            'document_id' => $d->id,
            'document_type_id' => $d->document_type_id,
            'type_label' => $labelById[$d->document_type_id]->label ?? null,
            'name' => $d->original_name,
            'source' => $d->source_type, // 'upload' (manual) or 'esign'
        ])->values()->toArray();
    }

    // ── Listing gates (unchanged behaviour) ──

    private function checkPhotos(Property $property): array
    {
        $count = $this->countPhotos($property);
        $required = 4;

        return [
            'passed' => $count >= $required,
            'label' => 'Photos',
            'detail' => $count >= $required
                ? $count . ' photos uploaded'
                : 'Only ' . $count . ' photos (minimum ' . $required . ' required)',
            'action_label' => 'Upload Photos',
            'action_url' => route('corex.properties.show', $property->id) . '?tab=gallery',
        ];
    }

    private function checkDetailsComplete(Property $property): array
    {
        $required = [
            'address' => $property->address ?: $property->street_name,
            'suburb' => $property->suburb,
            'town' => $property->town,
            'province' => $property->province,
            'price' => $property->price,
            'property_type' => $property->property_type,
            'erf_size' => $property->erf_size_m2,
        ];

        $missing = [];
        foreach ($required as $field => $value) {
            if ($value === null || $value === '' || $value === 0) {
                // beds/baths can be 0 for vacant land — only flag truly empty
                $missing[] = str_replace('_', ' ', $field);
            }
        }

        return [
            'passed' => empty($missing),
            'label' => 'Listing details',
            'detail' => empty($missing)
                ? 'All required listing details complete'
                : 'Missing: ' . implode(', ', $missing),
            'action_label' => 'Complete Details',
            'action_url' => route('corex.properties.show', $property->id) . '?tab=info',
        ];
    }

    // ── Helpers ──

    private function countPhotos(Property $property): int
    {
        return count($property->gallery_images_json ?? [])
            + count($property->images_json ?? []);
    }

    private function buildHistoricalChecklist(Property $property): array
    {
        $snapshot = $property->compliance_snapshot_data ?? [];

        return $snapshot['checklist'] ?? [
            'authority_to_market' => ['passed' => true, 'label' => 'Authority to Market', 'detail' => 'Verified at snapshot time'],
            'photos' => ['passed' => true, 'label' => 'Photos', 'detail' => 'Verified at snapshot time'],
            'details_complete' => ['passed' => true, 'label' => 'Listing details', 'detail' => 'Verified at snapshot time'],
        ];
    }
}
