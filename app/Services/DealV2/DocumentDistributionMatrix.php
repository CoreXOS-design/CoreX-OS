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
     * PARTY-FIRST view for the editable settings UI:
     * [party_role => [document_type_id => ['delivery_mode'=>, 'pipeline_step_id'=>]]].
     * Includes both type-level (null stage) and stage-scoped active rules.
     */
    public function partyMatrix(int $agencyId): array
    {
        $rows = DealStageDocumentRule::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->get(['document_type_id', 'party_role', 'delivery_mode', 'pipeline_step_id']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->party_role][$r->document_type_id] = [
                'delivery_mode'    => $r->delivery_mode,
                'pipeline_step_id' => $r->pipeline_step_id,
            ];
        }
        return $out;
    }

    /**
     * Distinct pipeline-step NAMES for the "optional stage" dropdown, each mapped to a
     * representative step id (default template first). "Any stage" is the null option.
     * @return array<string,int>  step name => representative step id
     */
    public function stageOptions(int $agencyId): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('deal_pipeline_steps as s')
            ->join('deal_pipeline_templates as t', 's.pipeline_template_id', '=', 't.id')
            ->where('t.agency_id', $agencyId)
            ->whereNull('s.deleted_at')
            ->orderByDesc('t.is_default')->orderBy('s.position')
            ->get(['s.id', 's.name']);

        $out = [];
        foreach ($rows as $r) {
            if (! isset($out[$r->name])) {
                $out[$r->name] = (int) $r->id;   // first (default-template) wins
            }
        }
        return $out;
    }

    /**
     * PARTY-FIRST editable save: replace the document set a party role receives.
     * $entries = [document_type_id => ['delivery_mode'=>, 'pipeline_step_id'=> ?int]].
     * One logical rule per (party, type): existing (party,type) rules of any stage are
     * cleared and the chosen delivery+stage written. Unlisted types are soft-deleted.
     */
    public function setPartyDistribution(int $agencyId, string $partyRole, array $entries, ?int $actorId = null): void
    {
        if (! array_key_exists($partyRole, self::PARTY_ROLES)) {
            return;
        }
        $wantedTypeIds = array_map('intval', array_keys($entries));

        // Existing rules for this party (any stage, incl. trashed).
        $existing = DealStageDocumentRule::withTrashed()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('party_role', $partyRole)
            ->get();

        // Soft-delete rules for types no longer wanted.
        foreach ($existing as $rule) {
            if (! in_array((int) $rule->document_type_id, $wantedTypeIds, true) && ! $rule->trashed()) {
                $rule->delete();
            }
        }

        foreach ($entries as $typeId => $opts) {
            $typeId   = (int) $typeId;
            $mode     = ($opts['delivery_mode'] ?? '') === DealStageDocumentRule::MODE_DIRECT_ATTACHMENT
                ? DealStageDocumentRule::MODE_DIRECT_ATTACHMENT : DealStageDocumentRule::MODE_SECURE_LINK;
            $stepId   = ! empty($opts['pipeline_step_id']) ? (int) $opts['pipeline_step_id'] : null;

            // Collapse to one rule per (party, type): drop any other-stage rows for this pair.
            $forType = $existing->where('document_type_id', $typeId);
            $keep = null;
            foreach ($forType as $rule) {
                if ($keep === null) {
                    $keep = $rule;
                } elseif (! $rule->trashed()) {
                    $rule->delete();
                }
            }
            if ($keep) {
                $keep->fill([
                    'pipeline_step_id' => $stepId,
                    'delivery_mode'    => $mode,
                    'is_active'        => true,
                    'deleted_at'       => null,
                ])->save();
            } else {
                DealStageDocumentRule::create([
                    'agency_id'          => $agencyId,
                    'pipeline_step_id'   => $stepId,
                    'document_type_id'   => $typeId,
                    'party_role'         => $partyRole,
                    'delivery_mode'      => $mode,
                    'auto_on_stage_tick' => $stepId !== null,   // stage-scoped rules can auto-fire
                    'is_active'          => true,
                    'created_by_id'      => $actorId,
                ]);
            }
        }
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
