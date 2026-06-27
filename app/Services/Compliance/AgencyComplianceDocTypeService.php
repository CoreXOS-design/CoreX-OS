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
            ->get(['dt.id', 'dt.slug', 'dt.label', 'dt.grouping']);
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

    // ─────────────────────────────────────────────────────────────────────
    // AT-105 — per-agency "Save To" destination config for the PDF Splitter.
    // Property + Contact are two INDEPENDENT flags (tick either, both, or
    // neither). Stored on the same per-agency pivot. NULL on either column
    // means "use the grouping-derived default" so existing rows need no
    // backfill and a new type just inherits a sensible destination.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Default destination for a document type, derived from its catalogue
     * `grouping`. Contact-grouped types (fica, ids, por) default to the
     * contact; everything else defaults to the property — which preserves the
     * splitter's historical behaviour of filing every output to the property.
     *
     * @return array{property: bool, contact: bool}
     */
    public function defaultDestinationForGrouping(?string $grouping): array
    {
        if ($grouping === 'contact') {
            return ['property' => false, 'contact' => true];
        }

        // property | shared | null | anything-unknown → property only.
        return ['property' => true, 'contact' => false];
    }

    /**
     * Resolve a stored row (nullable flags) over the grouping default.
     *
     * @return array{property: bool, contact: bool}
     */
    private function resolveDestination(?int $storedProperty, ?int $storedContact, ?string $grouping): array
    {
        $default = $this->defaultDestinationForGrouping($grouping);

        return [
            'property' => $storedProperty === null ? $default['property'] : (bool) $storedProperty,
            'contact'  => $storedContact  === null ? $default['contact']  : (bool) $storedContact,
        ];
    }

    /**
     * Effective destination map for the Settings screen, keyed by
     * document_type_id. Covers every ACTIVE catalogue type, merging this
     * agency's stored choice over the grouping default.
     *
     * @return array<int, array{property: bool, contact: bool}>
     */
    public function destinationMapFor(int $agencyId): array
    {
        $stored = DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get(['document_type_id', 'save_to_property', 'save_to_contact'])
            ->keyBy('document_type_id');

        $types = DB::table('document_types')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'grouping']);

        $map = [];
        foreach ($types as $type) {
            $row = $stored->get($type->id);
            $map[$type->id] = $this->resolveDestination(
                $row->save_to_property ?? null,
                $row->save_to_contact ?? null,
                $type->grouping,
            );
        }

        return $map;
    }

    /**
     * Effective destination for one document type, keyed by its slug. Used by
     * the splitter, which knows each output's slug from the filename. Unknown
     * slugs fall back to the property (no-orphan default).
     *
     * @return array{property: bool, contact: bool}
     */
    public function destinationForSlug(int $agencyId, string $slug): array
    {
        $type = DB::table('document_types')
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->first(['id', 'grouping']);

        if (! $type) {
            return ['property' => true, 'contact' => false];
        }

        $row = DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->where('document_type_id', $type->id)
            ->whereNull('deleted_at')
            ->first(['save_to_property', 'save_to_contact']);

        return $this->resolveDestination(
            $row->save_to_property ?? null,
            $row->save_to_contact ?? null,
            $type->grouping,
        );
    }

    /**
     * Persist this agency's explicit "Save To" choice for one type. Stored
     * alongside the compliance flag on the same pivot row; updateOrInsert
     * restores a soft-deleted row and never re-seeds over a deliberate choice.
     */
    public function setDestination(int $agencyId, int $documentTypeId, bool $property, bool $contact): void
    {
        DB::table('agency_document_type_compliance')->updateOrInsert(
            ['agency_id' => $agencyId, 'document_type_id' => $documentTypeId],
            [
                'save_to_property' => $property,
                'save_to_contact'  => $contact,
                'deleted_at'       => null,
                'updated_at'       => now(),
                'created_at'       => DB::raw('COALESCE(created_at, NOW())'),
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AT-105 enhancement — per-agency CONTACT ROLE + FICA SLOT routing.
    // Same pattern as Save-To: the catalogue (`document_types`) carries the
    // default; `agency_document_type_compliance` carries a NULLABLE override
    // (NULL = inherit the catalogue default). Resolution = override over default.
    // ─────────────────────────────────────────────────────────────────────

    /** Allowed role tokens for contact_roles, shared with validation. */
    private const ROLE_TOKENS = ['seller_owner', 'buyer', 'tenant', 'landlord', 'lessor'];
    private const SLOT_TOKENS = ['id', 'por', 'fica_form', 'none'];

    /** Decode a stored JSON (or array) contact_roles value to a clean token list. */
    private function decodeRoles($raw): array
    {
        if (is_array($raw)) {
            $arr = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $arr = json_decode($raw, true) ?: [];
        } else {
            $arr = [];
        }

        return array_values(array_filter(array_map('strval', (array) $arr), fn ($r) => in_array($r, self::ROLE_TOKENS, true)));
    }

    /**
     * Resolve one type's routing (contact_roles SET + fica_slot), agency
     * override over catalogue default. NULL override = inherit catalogue.
     *
     * @return array{contact_roles: string[], fica_slot: string}
     */
    private function resolveRouting($overrideRoles, ?string $overrideSlot, $catalogueRoles, ?string $catalogueSlot): array
    {
        return [
            'contact_roles' => $overrideRoles !== null
                ? $this->decodeRoles($overrideRoles)
                : $this->decodeRoles($catalogueRoles),
            'fica_slot' => $overrideSlot !== null && $overrideSlot !== ''
                ? (in_array($overrideSlot, self::SLOT_TOKENS, true) ? $overrideSlot : 'none')
                : (in_array((string) $catalogueSlot, self::SLOT_TOKENS, true) ? (string) $catalogueSlot : 'none'),
        ];
    }

    /**
     * Effective routing map for the Settings screen, keyed by document_type_id.
     * Covers every ACTIVE catalogue type.
     *
     * @return array<int, array{contact_roles: string[], fica_slot: string}>
     */
    public function routingMapFor(int $agencyId): array
    {
        $stored = DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get(['document_type_id', 'contact_roles', 'fica_slot'])
            ->keyBy('document_type_id');

        $types = DB::table('document_types')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'contact_roles', 'fica_slot']);

        $map = [];
        foreach ($types as $type) {
            $row = $stored->get($type->id);
            $map[$type->id] = $this->resolveRouting(
                $row->contact_roles ?? null,
                $row->fica_slot ?? null,
                $type->contact_roles,
                $type->fica_slot,
            );
        }

        return $map;
    }

    /**
     * Effective routing for the splitter review screen, keyed by SLUG. The
     * splitter knows each page's slug from the chosen label.
     *
     * @return array<string, array{label: string, contact_roles: string[], fica_slot: string}>
     */
    public function routingMapBySlugFor(int $agencyId): array
    {
        $stored = DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get(['document_type_id', 'contact_roles', 'fica_slot'])
            ->keyBy('document_type_id');

        $types = DB::table('document_types')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'slug', 'label', 'contact_roles', 'fica_slot']);

        $map = [];
        foreach ($types as $type) {
            $row = $stored->get($type->id);
            $routing = $this->resolveRouting(
                $row->contact_roles ?? null,
                $row->fica_slot ?? null,
                $type->contact_roles,
                $type->fica_slot,
            );
            $map[$type->slug] = [
                'label'         => $type->label,
                'contact_roles' => $routing['contact_roles'],
                'fica_slot'     => $routing['fica_slot'],
            ];
        }

        return $map;
    }

    /**
     * Effective routing for ONE type by slug. Unknown slug → []/none.
     *
     * @return array{contact_roles: string[], fica_slot: string}
     */
    public function routingForSlug(int $agencyId, string $slug): array
    {
        $type = DB::table('document_types')
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->first(['id', 'contact_roles', 'fica_slot']);

        if (! $type) {
            return ['contact_roles' => [], 'fica_slot' => 'none'];
        }

        $row = DB::table('agency_document_type_compliance')
            ->where('agency_id', $agencyId)
            ->where('document_type_id', $type->id)
            ->whereNull('deleted_at')
            ->first(['contact_roles', 'fica_slot']);

        return $this->resolveRouting(
            $row->contact_roles ?? null,
            $row->fica_slot ?? null,
            $type->contact_roles,
            $type->fica_slot,
        );
    }

    /**
     * Persist this agency's explicit contact_roles SET + fica_slot for one type.
     * Stored alongside the compliance/Save-To flags on the same pivot row.
     *
     * @param string[] $contactRoles
     */
    public function setRoleConfig(int $agencyId, int $documentTypeId, array $contactRoles, string $ficaSlot): void
    {
        $roles = array_values(array_filter($contactRoles, fn ($r) => in_array($r, self::ROLE_TOKENS, true)));
        $slot  = in_array($ficaSlot, self::SLOT_TOKENS, true) ? $ficaSlot : 'none';

        DB::table('agency_document_type_compliance')->updateOrInsert(
            ['agency_id' => $agencyId, 'document_type_id' => $documentTypeId],
            [
                'contact_roles' => json_encode($roles),
                'fica_slot'     => $slot,
                'deleted_at'    => null,
                'updated_at'    => now(),
                'created_at'    => DB::raw('COALESCE(created_at, NOW())'),
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
