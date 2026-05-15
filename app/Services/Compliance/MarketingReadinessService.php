<?php

namespace App\Services\Compliance;

use App\Models\DevSetting;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MarketingReadinessService
{
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
                    'dev_override' => ['passed' => true, 'detail' => 'Compliance checks disabled in Dev Settings'],
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

        // Gate 1: Authority to Market (mandate OR marketing permission — either suffices)
        $checklist['authority_to_market'] = $this->checkAuthorityToMarket($property);
        if (!$checklist['authority_to_market']['passed']) {
            $blockedBy[] = $checklist['authority_to_market']['detail'];
            $nextActions[] = [
                'label' => 'Send Marketing Pack',
                'action_url' => route('corex.properties.show', $property->id) . '?tab=drive',
            ];
        }

        // Gate 2: All sellers FICA approved
        $checklist['fica_sellers'] = $this->checkSellersFica($property);
        if (!$checklist['fica_sellers']['passed']) {
            $blockedBy[] = $checklist['fica_sellers']['detail'];
            $nextActions[] = [
                'label' => 'Complete FICA for all sellers',
                'action_url' => route('corex.properties.show', $property->id) . '?tab=contacts',
            ];
        }

        // Gate 4: Listing has photos (>= 4)
        $checklist['photos'] = $this->checkPhotos($property);
        if (!$checklist['photos']['passed']) {
            $blockedBy[] = $checklist['photos']['detail'];
            $nextActions[] = [
                'label' => 'Upload at least 4 property photos',
                'action_url' => route('corex.properties.show', $property->id) . '?tab=gallery',
            ];
        }

        // Gate 5: Listing details complete
        $checklist['details_complete'] = $this->checkDetailsComplete($property);
        if (!$checklist['details_complete']['passed']) {
            $blockedBy[] = $checklist['details_complete']['detail'];
            $nextActions[] = [
                'label' => 'Complete missing property details',
                'action_url' => route('corex.properties.show', $property->id),
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

        $mandateDoc = $this->findSignedMandate($property);
        $marketingPermDoc = $this->findSignedMarketingPermission($property);

        $snapshotData = [
            'snapshot_version' => 1,
            'snapshotted_by_user_id' => $by->id,
            'snapshotted_by_name' => $by->name,
            'sellers' => $sellerData,
            'documents' => [
                'mandate' => $mandateDoc ? [
                    'docuperfect_document_id' => $mandateDoc->id,
                    'name' => $mandateDoc->name,
                    'document_type' => $mandateDoc->document_type,
                ] : null,
                'marketing_permission' => $marketingPermDoc ? [
                    'docuperfect_document_id' => $marketingPermDoc->id,
                    'name' => $marketingPermDoc->name,
                ] : null,
            ],
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

    // ── Gate checks ──

    private function checkAuthorityToMarket(Property $property): array
    {
        $mandate = $this->findSignedMandate($property);
        $marketingPerm = $this->findSignedMarketingPermission($property);

        if ($mandate && $marketingPerm) {
            return [
                'passed' => true,
                'detail' => 'Mandate signed (doc #' . $mandate->id . ') + Marketing Permission signed (doc #' . $marketingPerm->id . ')',
            ];
        }
        if ($mandate) {
            return [
                'passed' => true,
                'detail' => 'Mandate signed (doc #' . $mandate->id . ')',
            ];
        }
        if ($marketingPerm) {
            return [
                'passed' => true,
                'detail' => 'Marketing Permission signed (doc #' . $marketingPerm->id . ')',
            ];
        }

        return [
            'passed' => false,
            'detail' => 'No signed authority document (mandate or marketing permission)',
        ];
    }

    private function checkSellersFica(Property $property): array
    {
        $sellers = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get();

        if ($sellers->isEmpty()) {
            return [
                'passed' => false,
                'detail' => 'No sellers linked to property',
            ];
        }

        $failed = [];
        foreach ($sellers as $seller) {
            $fica = DB::table('fica_submissions')
                ->where('contact_id', $seller->id)
                ->where('status', 'approved')
                ->orderByDesc('id')
                ->first();

            if (!$fica) {
                $failed[] = trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? ''));
            }
        }

        if (!empty($failed)) {
            return [
                'passed' => false,
                'detail' => 'FICA not approved for: ' . implode(', ', $failed),
            ];
        }

        return [
            'passed' => true,
            'detail' => 'All ' . $sellers->count() . ' seller(s) FICA approved',
        ];
    }

    private function checkPhotos(Property $property): array
    {
        $count = $this->countPhotos($property);
        $required = 4;

        return [
            'passed' => $count >= $required,
            'detail' => $count >= $required
                ? $count . ' photos uploaded'
                : 'Only ' . $count . ' photos (minimum ' . $required . ' required)',
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
            'detail' => empty($missing)
                ? 'All required listing details complete'
                : 'Missing: ' . implode(', ', $missing),
        ];
    }

    // ── Helpers ──

    private function findSignedMandate(Property $property): ?object
    {
        return DB::table('docuperfect_documents')
            ->where('property_id', $property->id)
            ->where('document_type', 'mandate')
            ->whereNull('deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('signature_templates')
                  ->whereColumn('signature_templates.document_id', 'docuperfect_documents.id')
                  ->where('signature_templates.status', 'completed')
                  ->whereNull('signature_templates.deleted_at');
            })
            ->orderByDesc('id')
            ->first();
    }

    private function findSignedMarketingPermission(Property $property): ?object
    {
        return DB::table('docuperfect_documents')
            ->where('property_id', $property->id)
            ->where('document_type', 'like', '%marketing%permission%')
            ->whereNull('deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('signature_templates')
                  ->whereColumn('signature_templates.document_id', 'docuperfect_documents.id')
                  ->where('signature_templates.status', 'completed')
                  ->whereNull('signature_templates.deleted_at');
            })
            ->orderByDesc('id')
            ->first();
    }

    private function countPhotos(Property $property): int
    {
        return count($property->gallery_images_json ?? [])
            + count($property->images_json ?? []);
    }

    private function buildHistoricalChecklist(Property $property): array
    {
        $snapshot = $property->compliance_snapshot_data ?? [];

        return $snapshot['checklist'] ?? [
            'authority_to_market' => ['passed' => true, 'detail' => 'Verified at snapshot time'],
            'fica_sellers' => ['passed' => true, 'detail' => 'Verified at snapshot time'],
            'photos' => ['passed' => true, 'detail' => 'Verified at snapshot time'],
            'details_complete' => ['passed' => true, 'detail' => 'Verified at snapshot time'],
        ];
    }
}
