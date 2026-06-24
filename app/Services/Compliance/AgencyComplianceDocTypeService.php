<?php

namespace App\Services\Compliance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an agency's CONFIGURABLE marketing-compliance required document
 * types. The `document_types` catalogue is global; the per-agency required
 * mapping lives in `agency_document_type_compliance`.
 *
 * Reads use raw queries (no Eloquent global scope) so the gate works even in
 * the unauthenticated public-listing context, mirroring how
 * MarketingReadinessService already reads fica_submissions.
 */
class AgencyComplianceDocTypeService
{
    /**
     * Active document types this agency requires for marketing compliance.
     * Returns rows of {id, slug, label}. Never seeds (pure read).
     */
    public function requiredTypesFor(int $agencyId): Collection
    {
        return DB::table('agency_document_type_compliance as adtc')
            ->join('document_types as dt', 'dt.id', '=', 'adtc.document_type_id')
            ->where('adtc.agency_id', $agencyId)
            ->where('adtc.is_compliance_required', true)
            ->whereNull('adtc.deleted_at')
            ->whereNull('dt.deleted_at')
            ->where('dt.is_active', true)
            ->orderBy('dt.sort_order')
            ->get(['dt.id', 'dt.slug', 'dt.label']);
    }

    /**
     * Map of document_type_id => bool(required) for THIS agency, for the
     * Settings screen. Types with no row default to false.
     */
    public function complianceMapFor(int $agencyId): array
    {
        return DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('is_compliance_required', 'document_type_id')
            ->map(fn ($v) => (bool) $v)
            ->toArray();
    }

    /**
     * Set (or clear) the compliance-required flag for one type for one agency.
     * Toggling off keeps the row with the flag false — the agency stays
     * "initialised" so ensureDefaults() never re-seeds over a deliberate
     * choice. updateOrInsert also restores a previously soft-deleted row.
     */
    public function setRequired(int $agencyId, int $documentTypeId, bool $required): void
    {
        DB::table('agency_document_type_compliance')->updateOrInsert(
            ['agency_id' => $agencyId, 'document_type_id' => $documentTypeId],
            [
                'is_compliance_required' => $required,
                'deleted_at'             => null,
                'updated_at'             => now(),
                'created_at'             => DB::raw('COALESCE(created_at, NOW())'),
            ],
        );
    }

    /**
     * Seed default required types for an agency that has NEVER been
     * initialised (no rows at all, including soft-deleted). Idempotent and
     * safe to call on every settings-page load / agency creation. An agency
     * that has deliberately cleared all flags keeps its (false) rows and is
     * therefore NOT re-seeded.
     */
    public function ensureDefaults(int $agencyId): void
    {
        $hasAny = DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->exists();

        if ($hasAny) {
            return;
        }

        $defaultSlugs = config('corex-compliance.default_required_slugs', ['mandate', 'fica', 'disclosure']);

        $typeIds = DB::table('document_types')
            ->whereIn('slug', $defaultSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        $now = now();
        foreach ($typeIds as $typeId) {
            DB::table('agency_document_type_compliance')->updateOrInsert(
                ['agency_id' => $agencyId, 'document_type_id' => $typeId],
                [
                    'is_compliance_required' => true,
                    'deleted_at'             => null,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ],
            );
        }
    }
}
