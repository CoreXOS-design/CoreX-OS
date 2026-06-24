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
     * Consumes the SHARED evaluation (single source of truth) so the gate and
     * the Drive-tab checklist can never disagree.
     *
     * @return array<string, array{passed:bool,label:string,detail:string,action_label:?string,action_url:string}>
     */
    private function requiredDocTypeGates(Property $property): array
    {
        $ficaSlug = config('corex-compliance.fica_slug', 'fica');
        $driveTab = route('corex.properties.show', $property->id) . '?tab=drive';
        $contactsTab = route('corex.properties.show', $property->id) . '?tab=contacts';

        $gates = [];
        foreach ($this->evaluateRequiredTypes($property) as $item) {
            $type = $item['type'];
            $present = $item['present'];
            $isFica = $type->slug === $ficaSlug;

            if ($present) {
                $detail = $isFica ? 'All sellers FICA approved' : ($type->label . ' on file');
            } elseif ($isFica) {
                $detail = $item['fica_detail'] ?? ('Missing: ' . $type->label);
            } else {
                $detail = 'Missing: ' . $type->label . ' — upload the signed document to the property Drive';
            }

            // FICA resolves at the seller contact (verify FICA), other types on the Drive.
            $actionUrl = $isFica
                ? ($this->primarySellerId($property)
                    ? route('compliance.fica.create') . '?contact_id=' . $this->primarySellerId($property)
                    : $contactsTab)
                : $driveTab;
            $actionLabel = $present
                ? null
                : ($isFica
                    ? ($this->primarySellerId($property) ? 'Verify seller FICA' : 'Link a seller')
                    : 'Upload ' . $type->label);

            $gates[$type->slug] = [
                'passed' => $present,
                'label' => $type->label,
                'detail' => $detail,
                'action_label' => $actionLabel,
                'action_url' => $actionUrl,
            ];
        }

        return $gates;
    }

    /**
     * Single source of truth for per-required-type presence. Both the gate
     * (requiredDocTypeGates) and the Drive-tab checklist (complianceChecklistFor)
     * derive from this — guaranteeing they never disagree.
     *
     * For ordinary required types, presence = a non-soft-deleted Drive Document
     * of that type on the property OR on a seller contact (no approval status
     * checked — instant-unlock doctrine).
     *
     * FICA is the exception: it is a CONTACT-level compliance fact, not a
     * property document. The FICA required-type is satisfied only by the
     * authoritative `fica_submissions` approval — every linked seller must be
     * FICA-approved (and at least one seller must be linked). A FICA PDF on the
     * property Drive does NOT satisfy it. This removes the duplicate weaker
     * path and means a property cannot go live without a FICA-verified seller.
     *
     * @return list<array{type:object,present:bool,doc:?object,via:?string,fica_detail:?string}>
     */
    private function evaluateRequiredTypes(Property $property): array
    {
        $agencyId = (int) $property->agency_id;
        $requiredTypes = $agencyId > 0
            ? $this->docTypes->requiredTypesFor($agencyId)
            : collect();

        if ($requiredTypes->isEmpty()) {
            return [];
        }

        $sellerIds = $this->sellerContactIds($property);
        $typeIds = $requiredTypes->pluck('id')->all();
        $ficaSlug = config('corex-compliance.fica_slug', 'fica');

        $cols = ['d.id', 'd.document_type_id', 'd.original_name', 'd.disk', 'd.source_type'];

        $propertyDocs = DB::table('document_properties as dp')
            ->join('documents as d', 'd.id', '=', 'dp.document_id')
            ->where('dp.property_id', $property->id)
            ->whereIn('d.document_type_id', $typeIds)
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.id')
            ->get($cols)
            ->keyBy('document_type_id');

        $contactDocs = collect();
        if ($sellerIds->isNotEmpty()) {
            $contactDocs = DB::table('document_contacts as dc')
                ->join('documents as d', 'd.id', '=', 'dc.document_id')
                ->whereIn('dc.contact_id', $sellerIds)
                ->whereIn('d.document_type_id', $typeIds)
                ->whereNull('d.deleted_at')
                ->orderByDesc('d.id')
                ->get($cols)
                ->keyBy('document_type_id');
        }

        $ficaChecked = false;
        $ficaPass = false;
        $ficaDetail = null;

        $out = [];
        foreach ($requiredTypes as $type) {
            // FICA: a contact-level gate on fica_submissions, NOT a Drive doc.
            if ($type->slug === $ficaSlug) {
                if (!$ficaChecked) {
                    [$ficaPass, $ficaDetail] = $this->checkSellersFicaSubmissions($property, $sellerIds);
                    $ficaChecked = true;
                }
                $out[] = [
                    'type' => $type,
                    'present' => $ficaPass,
                    'doc' => null,
                    'via' => $ficaPass ? 'contact_fica' : null,
                    'fica_detail' => $ficaPass ? null : $ficaDetail,
                ];
                continue;
            }

            // Ordinary required types: presence of a typed Drive document.
            $doc = $propertyDocs->get($type->id) ?? $contactDocs->get($type->id);
            $via = $propertyDocs->has($type->id) ? 'property' : ($contactDocs->has($type->id) ? 'contact' : null);

            $out[] = [
                'type' => $type,
                'present' => $doc !== null,
                'doc' => $doc,
                'via' => $via,
                'fica_detail' => null,
            ];
        }

        return $out;
    }

    /**
     * Drive-tab compliance checklist for a property — the SAME per-type
     * presence the gate uses, plus the inline-upload routing (document type
     * pre-set; contact-level types route to the primary seller contact so the
     * uploaded doc lands where the gate reads it).
     *
     * When the gate is short-circuited ready (dev override or an existing
     * compliance snapshot), every required row reflects satisfied — so the
     * checklist can never contradict a LIVE/ready gate.
     *
     * @return list<array<string,mixed>>
     */
    public function complianceChecklistFor(Property $property): array
    {
        $items = $this->evaluateRequiredTypes($property);
        if (empty($items)) {
            return [];
        }

        // Mirror statusFor()'s ready short-circuits so the checklist agrees
        // with a LIVE (snapshotted) or dev-override gate.
        $forceSatisfied = DevSetting::bool('compliance_checks_disabled')
            || $property->compliance_snapshot_at !== null;

        $ficaSlug = config('corex-compliance.fica_slug', 'fica');
        $primarySeller = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->first();
        $sellerName = $primarySeller
            ? (trim(($primarySeller->first_name ?? '') . ' ' . ($primarySeller->last_name ?? '')) ?: ($primarySeller->name ?? 'seller'))
            : null;

        $filesStoreUrl = route('corex.properties.files.store', $property->id);
        $contactsTab = route('corex.properties.show', $property->id) . '?tab=contacts';

        $rows = [];
        foreach ($items as $item) {
            $type = $item['type'];
            $present = $item['present'] || $forceSatisfied;

            // FICA is a contact-level gate: no property upload — link to the
            // seller's FICA (or prompt to link a seller). Satisfied only by the
            // seller's fica_submissions approval, never a property Drive file.
            if ($type->slug === $ficaSlug) {
                $rows[] = [
                    'slug' => $type->slug,
                    'label' => $type->label,
                    'type_id' => $type->id,
                    'grouping' => 'contact',
                    'present' => $present,
                    'is_contact_fica' => true,
                    'satisfied_by_snapshot' => $forceSatisfied && !$item['present'],
                    'via' => $item['via'],
                    'doc' => null,
                    'detail' => $present
                        ? 'Seller FICA approved'
                        : ($item['fica_detail'] ?? 'Seller FICA outstanding'),
                    'action_url' => $primarySeller
                        ? route('compliance.fica.create') . '?contact_id=' . $primarySeller->id
                        : $contactsTab,
                    'action_label' => $present ? null : ($primarySeller ? 'Verify FICA' : 'Link a seller'),
                    'seller_name' => $sellerName,
                ];
                continue;
            }

            // Other contact-grouped doc types (e.g. ID, proof of residence) still
            // route their upload to the seller contact's drive when one exists.
            $routeToContact = ($type->grouping ?? 'shared') === 'contact' && $primarySeller !== null;

            $rows[] = [
                'slug' => $type->slug,
                'label' => $type->label,
                'type_id' => $type->id,
                'grouping' => $type->grouping ?? 'shared',
                'present' => $present,
                'is_contact_fica' => false,
                'satisfied_by_snapshot' => $forceSatisfied && !$item['present'],
                'via' => $item['via'],
                'doc' => $item['doc'] ? [
                    'name' => $item['doc']->original_name,
                    'source' => $item['doc']->source_type, // 'esign' | 'upload' | ...
                ] : null,
                // Inline upload — reuses PropertyFileController::store, document
                // type pre-set so the agent can't mistype it.
                'upload_url' => $filesStoreUrl,
                'document_type_id' => $type->id,
                'upload_contact_id' => $routeToContact ? $primarySeller->id : null,
                'upload_contact_name' => $routeToContact ? $sellerName : null,
            ];
        }

        return $rows;
    }

    /** Seller/owner/landlord/lessor contact ids linked to the property. */
    private function sellerContactIds(Property $property): \Illuminate\Support\Collection
    {
        return $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->pluck('contacts.id');
    }

    /** First linked seller contact id (for routing the FICA action), or null. */
    private function primarySellerId(Property $property): ?int
    {
        $id = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->value('contacts.id');

        return $id ? (int) $id : null;
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
