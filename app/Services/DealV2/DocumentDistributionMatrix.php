<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Collection;

/**
 * AT-227 — the Document-type Distribution Matrix: the SINGLE SOURCE OF TRUTH for
 * "which document types are distributable to which party roles", agency-scoped.
 *
 * It is NOT a new table. A TYPE-LEVEL distribution rule is exactly a
 * `deal_stage_document_rules` row with `pipeline_step_id = NULL` (independent of any
 * pipeline stage), keyed unique on (agency_id, NULL step, document_type_id, party_role).
 *
 * ONE matrix, TWO consumers (do not fork):
 *   1. AT-228 DR2 party send-buttons — reads typesForParty()/rulesForParty() to
 *      pre-load a party's default documents.
 *   2. m6 e-sign completion distribution — reads the same rules on ceremony completion.
 *
 * Both consume this class; the settings UI writes through setTypeDistribution().
 */
class DocumentDistributionMatrix
{
    /** The party roles a document can be distributed to (reuses deal_v2_contacts.role vocabulary). */
    public const PARTY_ROLES = [
        'seller'            => 'Seller',
        'buyer'             => 'Buyer',
        'transfer_attorney' => 'Transferring Attorney',
        'bond_originator'   => 'Bond Originator',
        'bond_attorney'     => 'Bond Attorney',
        'conveyancer'       => 'Conveyancer',
    ];

    /**
     * Type-level rule = null pipeline step. The global AgencyScope is bypassed and the
     * tenant is filtered EXPLICITLY, so consumers that run without an authenticated user
     * (m6 e-sign completion in a queue/listener) still read the right agency's matrix.
     */
    private function baseQuery(int $agencyId)
    {
        return DealStageDocumentRule::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('pipeline_step_id')
            ->where('is_active', true);
    }

    /** Active party roles a given document type distributes to. */
    public function partyRolesForType(int $agencyId, int $documentTypeId): array
    {
        return $this->baseQuery($agencyId)
            ->where('document_type_id', $documentTypeId)
            ->pluck('party_role')->unique()->values()->all();
    }

    /** Is this document type distributable to anyone at all? */
    public function isDistributable(int $agencyId, int $documentTypeId): bool
    {
        return $this->baseQuery($agencyId)->where('document_type_id', $documentTypeId)->exists();
    }

    /**
     * CONSUMER QUERY (AT-228 + m6): the document TYPES distributable to a party role.
     * @return Collection<int,DocumentType>
     */
    public function typesForParty(int $agencyId, string $partyRole): Collection
    {
        $ids = $this->baseQuery($agencyId)->where('party_role', $partyRole)
            ->pluck('document_type_id')->unique();

        return DocumentType::query()->whereIn('id', $ids)->orderBy('sort_order')->get();
    }

    /**
     * CONSUMER QUERY (AT-228 + m6): the raw rules (carry delivery_mode) for a party role.
     * @return Collection<int,DealStageDocumentRule>
     */
    public function rulesForParty(int $agencyId, string $partyRole): Collection
    {
        return $this->baseQuery($agencyId)->where('party_role', $partyRole)
            ->with('documentType')->get();
    }

    /**
     * The full matrix for the settings UI: [document_type_id => [party_role, ...]].
     */
    public function matrix(int $agencyId): array
    {
        return $this->baseQuery($agencyId)->get(['document_type_id', 'party_role'])
            ->groupBy('document_type_id')
            ->map(fn ($rows) => $rows->pluck('party_role')->unique()->values()->all())
            ->all();
    }

    /**
     * Replace the party-role set a document type distributes to (agency-scoped, idempotent).
     * Adds missing rules (restoring soft-deleted ones), soft-deletes removed ones. Only the
     * type-level (null-stage) rules are touched — stage rules are never affected.
     */
    public function setTypeDistribution(int $agencyId, int $documentTypeId, array $partyRoles, ?int $actorId = null): void
    {
        $wanted = collect($partyRoles)->filter(fn ($r) => array_key_exists($r, self::PARTY_ROLES))->unique()->values();

        // Existing (incl. soft-deleted) null-stage rules for this type.
        $existing = DealStageDocumentRule::withTrashed()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('pipeline_step_id')
            ->where('document_type_id', $documentTypeId)
            ->get()
            ->keyBy('party_role');

        foreach ($wanted as $role) {
            $rule = $existing->get($role);
            if ($rule) {
                $rule->fill(['is_active' => true, 'deleted_at' => null])->save();
            } else {
                DealStageDocumentRule::create([
                    'agency_id'          => $agencyId,
                    'pipeline_step_id'   => null,
                    'document_type_id'   => $documentTypeId,
                    'party_role'         => $role,
                    'delivery_mode'      => DealStageDocumentRule::MODE_SECURE_LINK, // AT-228 overrides per send
                    'auto_on_stage_tick' => false,                                    // type-level: no stage
                    'is_active'          => true,
                    'created_by_id'      => $actorId,
                ]);
            }
        }

        // Soft-delete rules no longer wanted.
        foreach ($existing as $role => $rule) {
            if (! $wanted->contains($role) && $rule->trashed() === false) {
                $rule->delete();
            }
        }
    }
}
