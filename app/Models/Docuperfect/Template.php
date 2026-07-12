<?php

namespace App\Models\Docuperfect;

use Illuminate\Support\Facades\Log;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_templates';

    protected $fillable = [
        'name',
        'template_type',
        'document_type_id',
        'category',
        'page_count',
        'fields_json',
        'is_global',
        'is_esign',
        'party_mode',
        'wizard_config',
        'sections',
        'render_type',
        'blade_view',
        'signing_parties',
        'header_display',
        'editor_state',
        'cds_json',
        'field_mappings',
        'allowed_delivery_modes',
        'security_tier',
        'insertable_blocks',
        'owner_id',
        'archived_at',
    ];

    protected $casts = [
        'fields_json' => 'array',
        'wizard_config' => 'array',
        'sections' => 'array',
        'signing_parties' => 'array',
        'editor_state' => 'array',
        'cds_json' => 'array',
        'field_mappings' => 'array',
        'insertable_blocks' => 'array',
        'is_global' => 'boolean',
        'is_esign' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * E-sign reset Commit 5 (Q1) — single read site for field_mappings.
     *
     * Returns the authoritative tag-id → field-config map used across
     * the rendering pipeline + the wizard's editable-scope resolver.
     * Replaces direct `$template->field_mappings` reads, which today
     * fan out across six divergent sources (cds_json, editor_state.tags,
     * editor_state.mappings, tagged_html, field_mappings, fields_json,
     * blade_view) with no canonical owner — the divergence is what
     * caused Johan's "save 1 seller, reload 4 sellers" template revert.
     *
     * Priority order (first non-empty wins):
     *
     *   1. cds_drafts row for this template (most recent, not deleted).
     *      The builder writes to drafts continuously while the agent
     *      edits — if a draft exists, it represents the most recent
     *      authored state.
     *   2. editor_state.mappings — the builder's last full save into
     *      this template's `editor_state` JSON column.
     *   3. field_mappings column — legacy fallback for templates that
     *      pre-date the editor_state column.
     *
     * The result is normalised to a tag-id keyed array of field
     * descriptors. Empty sources yield an empty array.
     *
     * Companion behaviour:
     *
     *   • `pruneOrphanFieldMappings()` removes tag-ids that no longer
     *     appear in the saved tagged_html / cds_json — guards against
     *     the "blade has 1 seller, field_mappings has 14" divergence.
     *   • `applyDraftAndCleanup()` (TemplateController:cdsGenerate)
     *     deletes the applied draft on successful save so the next
     *     reload reads the freshly-saved template, not the now-stale
     *     draft.
     *
     * @return array<string, array<string, mixed>>
     */
    public function canonicalFieldMappings(): array
    {
        // Tier 1 — most recent IN-PROGRESS draft for this template.
        //
        // Filter on `status = 'draft'` so a previously-saved draft
        // (status='saved') doesn't override the template's
        // editor_state.mappings written by the same save. The
        // status='saved' rows stay alive in the DB (so old browser
        // URLs at /cds/builder/{saved_id} keep resolving) but they
        // no longer outrank the freshly-saved editor_state in the
        // canonical-accessor priority chain.
        if (\Illuminate\Support\Facades\Schema::hasTable('cds_drafts')) {
            $draft = \Illuminate\Support\Facades\DB::table('cds_drafts')
                ->where('source_template_id', $this->id)
                ->where('status', 'draft')
                ->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->first();
            if ($draft !== null && !empty($draft->mappings)) {
                $decoded = is_string($draft->mappings) ? json_decode($draft->mappings, true) : $draft->mappings;
                if (is_array($decoded) && count($decoded) > 0) {
                    return $decoded;
                }
            }
        }

        // Tier 2 — editor_state.mappings.
        $editorState = $this->editor_state ?? [];
        if (is_array($editorState) && !empty($editorState['mappings']) && is_array($editorState['mappings'])) {
            return $editorState['mappings'];
        }

        // Tier 3 — legacy field_mappings column.
        $legacy = $this->field_mappings ?? [];
        return is_array($legacy) ? $legacy : [];
    }

    /**
     * E-sign reset Commit 5 (Q1) — remove tag-ids from field_mappings
     * that are no longer referenced anywhere the renderer reads from
     * (tagged_html, cds_json sections, blade view). Called on save so
     * the next reload doesn't repopulate deleted blocks from the
     * orphan metadata.
     *
     * Returns the number of entries pruned (for audit logging).
     */
    public function pruneOrphanFieldMappings(): int
    {
        $current = $this->canonicalFieldMappings();
        if (empty($current)) {
            return 0;
        }
        $referenced = $this->collectReferencedTagIds();
        if (empty($referenced)) {
            // No reliable source of "which tags are still live" — bail
            // rather than nuking everything by accident.
            return 0;
        }
        $pruned = [];
        $removed = 0;
        foreach ($current as $tagId => $mapping) {
            if (in_array((string) $tagId, $referenced, true)) {
                $pruned[$tagId] = $mapping;
            } else {
                $removed++;
            }
        }
        if ($removed > 0) {
            // Write the pruned set back to all storage tiers so the
            // canonical accessor agrees with itself on the next read.
            $this->field_mappings = $pruned;
            $editorState = $this->editor_state ?? [];
            if (is_array($editorState)) {
                $editorState['mappings'] = $pruned;
                $this->editor_state = $editorState;
            }
            $this->save();
        }
        return $removed;
    }

    /**
     * Collect tag-ids referenced in any of the live-content sources:
     *   - editor_state.tagged_html (the builder's saved DOM)
     *   - cds_json sections' field_placeholder values
     *   - tagged_html stored on the template root (older schemas)
     *
     * Returns an array of tag-id strings.
     *
     * @return list<string>
     */
    private function collectReferencedTagIds(): array
    {
        $sources = [];
        $editorState = $this->editor_state ?? [];
        if (is_array($editorState)) {
            if (!empty($editorState['tagged_html']) && is_string($editorState['tagged_html'])) {
                $sources[] = $editorState['tagged_html'];
            }
            if (!empty($editorState['tags']) && is_array($editorState['tags'])) {
                foreach ($editorState['tags'] as $tagEntry) {
                    if (is_array($tagEntry) && !empty($tagEntry['id'])) {
                        $sources[] = '#' . $tagEntry['id'] . '#';
                    } elseif (is_string($tagEntry)) {
                        $sources[] = '#' . $tagEntry . '#';
                    }
                }
            }
        }
        $cdsJson = $this->cds_json ?? [];
        if (is_array($cdsJson)) {
            $sources[] = json_encode($cdsJson) ?: '';
        }
        $blob = implode("\n", $sources);
        if ($blob === '') {
            return [];
        }
        preg_match_all('/(tag-[A-Za-z0-9_-]+)/', $blob, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'docuperfect_template_branches', 'template_id', 'branch_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'template_id');
    }

    public function flows()
    {
        return $this->hasMany(Flow::class, 'template_id');
    }

    public function signatureZones()
    {
        return $this->hasMany(TemplateSignatureZone::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'templates');

        if ($scope === 'all') return $query;

        $branchId = $user->effectiveBranchId();

        return $query->where(function ($q) use ($branchId) {
            $q->where('is_global', true);
            if ($branchId) {
                $q->orWhereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                });
            }
        });
    }

    public function isPerParty(): bool
    {
        return $this->party_mode === 'per_party';
    }

    /**
     * Detect if this is a sales-context document.
     * Layered: explicit signing_parties roles > name pattern matching.
     * Accepts optional $propertySource ('properties' or 'rental_properties') for step-data context.
     */
    public function isSalesDocument(?string $propertySource = null): bool
    {
        // Layer 1: check signing_parties for explicit sales/rental roles
        $parties = $this->signing_parties ?? [];
        if (is_array($parties) && !empty($parties)) {
            $roles = array_map('strtolower', $parties);
            $hasSales = !empty(array_intersect($roles, ['seller', 'buyer']));
            $hasRental = !empty(array_intersect($roles, ['landlord', 'tenant', 'lessor', 'lessee']));
            if ($hasSales && !$hasRental) return true;
            if ($hasRental && !$hasSales) return false;
        }

        // Layer 2: explicit category / template_type set by the builder.
        // Authoritative — covers CDS templates whose signing_parties use the
        // generic owner_party/acquiring_party tokens (so Layer 1 can't tell)
        // and whose name carries no sales keyword. Without this a sales CDS
        // template falls through to the name heuristic and wrongly renders
        // Lessor. template_type 'cds' is neutral and skipped (no sale/rent
        // substring), so category decides.
        foreach ([strtolower((string) ($this->category ?? '')), strtolower((string) ($this->template_type ?? ''))] as $sig) {
            if ($sig === '') continue;
            if (str_contains($sig, 'sale') || $sig === 'otp') return true;
            if (str_contains($sig, 'rent') || str_contains($sig, 'lett') || str_contains($sig, 'lease')) return false;
        }

        // Layer 3: property source table
        if ($propertySource === 'properties') return true;
        if ($propertySource === 'rental_properties') return false;

        // Layer 4: template name pattern matching (last-resort fallback)
        $name = strtolower($this->name ?? '');
        return str_contains($name, 'sell') || str_contains($name, 'sale')
            || str_contains($name, 'authority') || str_contains($name, 'otp')
            || str_contains($name, 'purchase');
    }

    /**
     * THE LAST LINE — an alienation document can never be persisted as e-signable.
     *
     * `is_esign` had SEVEN writers and no guard: the importer hardcoded `is_esign => true`
     * on every document it created (the OTP included), TemplateController let a user flip the
     * flag straight from the settings screen with no check, the wizard "repairs" templates by
     * stamping it true, and migrations/seeders set it directly. Any one of them could mark a
     * deed of alienation e-signable — and a sale e-signed under ECTA §13(1) is VOID. Not
     * "flagged". Void. The deal does not exist.
     *
     * Guarding each writer is whack-a-mole and the next writer arrives unguarded. So the rule
     * lives where the data does: if the law blocks this template, `is_esign` cannot be true
     * when it hits the database — no matter who is writing, or how.
     *
     * This does not replace the wizard gate or the pack-eligibility computation; it is the
     * floor beneath them.
     */
    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            // Only interesting when someone is trying to turn e-signing ON.
            if (! $template->is_esign) {
                return;
            }

            if ($template->isEsignBlocked()) {
                $template->is_esign = false;

                Log::warning('ECTA §13(1): refused to persist is_esign=true on an alienation document', [
                    'template_id'   => $template->id,
                    'template_name' => $template->name,
                    'template_type' => $template->template_type,
                ]);
            }
        });
    }

    /**
     * Check if this template type is legally blocked from e-signing.
     * Sale agreements and OTPs must be signed with wet ink per Alienation of
     * Land Act §2(1) + ECTA §13(1).
     *
     * Spec: .ai/specs/esign-v3-complete-spec.md §5
     *
     * Layered defence — and an honest note on which layers are actually LIVE:
     *   Layer 1 — document_type_id slug match. **DEAD.** `docuperfect_document_types` has no
     *             `slug` column (verified on live, staging and qa1), so `documentType->slug`
     *             is always null and this layer has never fired for anyone.
     *   Layer 2 — template_type string. **EFFECTIVELY DEAD.** The real values in the wild are
     *             `sales` / `rental` / `standard` / `general`; none is in the blocked list, and
     *             the importer stamps every document it creates `general`.
     *   Layer 3 — name regex. **THE ONLY LIVE LAYER.** Everything below rests on it.
     *   Layer 4 — the saving guard above, so a blocked template cannot be stored e-signable.
     *   Layer 5 — every trigger writes to legal_block_audit_log (insert-only).
     *
     * Because Layer 3 carries the whole load, the pattern must cover how South Africans
     * actually name these documents — "Contract of Sale" is on live today and was NOT matched.
     * A mandate (Authority to Sell / sole mandate) is NOT an alienation document and must stay
     * e-signable: it authorises a sale, it does not effect one.
     */
    public function isEsignBlocked(): bool
    {
        $blockedSlugs = [
            'otp',
            'sale_agreement',
            'deed_of_sale',
            'deed_of_alienation',
            // offer_to_purchase is the pre-ES-1 slug that 6 templates already
            // carry — keep it blocked so existing classifications stay safe.
            'offer_to_purchase',
        ];

        // Layer 1 + 2 — slug or template_type string match
        $slug = $this->documentType?->slug ?? $this->template_type ?? '';
        if (in_array($slug, $blockedSlugs, true) && $slug !== '') {
            $this->logBlockTrigger('document_type_match', $slug);
            return true;
        }

        // Layer 3 — name regex with word boundaries. THE ONLY LIVE LAYER (see the docblock),
        // so it must cover how these documents are really named in South Africa.
        //
        // "Contract of Sale - Serenity Hills Eco Estate" is on LIVE right now and was NOT
        // matched by the old pattern — an alienation document one toggle away from being
        // e-signed and void. "Purchase agreement", "agreement of purchase and sale" and
        // "koopkontrak" are the same document under different names.
        //
        // What must NOT match, and this is the point of the word boundaries:
        //   - a MANDATE. "Exclusive Authority To Sell", "sole mandate" — a mandate AUTHORISES
        //     a sale, it does not EFFECT one. It is e-signable and blocking it would break the
        //     launch document.
        //   - "Photoshop" / "Photoshop Workflow" (the original reason for \b).
        $pattern = '/\b('
            . 'otp'
            . '|offer to purchase'
            . '|deed of (sale|alienation|transfer)'
            . '|(sale|purchase) agreement'
            . '|agreement (of|for) sale'
            . '|agreement of purchase and sale'
            . '|contract of sale'
            . '|sale of immovable property'
            . '|koopkontrak'
            . ')\b/i';
        if (preg_match($pattern, $this->name ?? '', $matches)) {
            $this->logBlockTrigger('name_pattern_match', $matches[0]);
            return true;
        }

        return false;
    }

    /**
     * ES-1 — write an insert-only audit row for every legal-block trigger.
     * Failure to write the log MUST NOT break the block — the block always
     * stands regardless of audit-log persistence.
     */
    private function logBlockTrigger(string $reason, ?string $matchedPattern): void
    {
        try {
            \App\Models\LegalBlockAuditLog::create([
                'agency_id'          => auth()->user()?->effectiveAgencyId(),
                'template_id'        => $this->id,
                'template_name'      => $this->name,
                'document_type_slug' => $this->documentType?->slug,
                'user_id'            => auth()->id(),
                'block_reason'       => $reason,
                'matched_pattern'    => $matchedPattern,
                'request_context'    => [
                    'route'      => request()->route()?->getName(),
                    'ip'         => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 500),
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Legal block audit log write failed: ' . $e->getMessage(), [
                'template_id' => $this->id,
                'reason'      => $reason,
            ]);
        }
    }

    /**
     * Get allowed delivery modes as an array.
     */
    public function getAllowedDeliveryModesArray(): array
    {
        $modes = $this->allowed_delivery_modes ?? 'esign,wet_ink,download';
        return array_filter(array_map('trim', explode(',', $modes)));
    }

    /**
     * Check if a specific delivery mode is allowed.
     */
    public function allowsDeliveryMode(string $mode): bool
    {
        // Sale agreements can NEVER use e-sign
        if ($mode === 'esign' && $this->isEsignBlocked()) {
            return false;
        }
        return in_array($mode, $this->getAllowedDeliveryModesArray());
    }

    /**
     * Get effective delivery modes (enforcing legal restrictions).
     */
    public function getEffectiveDeliveryModes(): array
    {
        $modes = $this->getAllowedDeliveryModesArray();
        if ($this->isEsignBlocked()) {
            $modes = array_values(array_diff($modes, ['esign']));
            if (empty($modes)) {
                $modes = ['wet_ink', 'download'];
            }
        }
        return $modes;
    }

    /**
     * Map generic signing party keys to display names based on document context.
     *
     * B1 — auto-numbers duplicates while preserving order:
     *   ['owner_party','owner_party','acquiring_party','agent'] + sales
     *     → ['Seller 1', 'Seller 2', 'Buyer', 'Agent']
     *
     * Singletons remain non-indexed (just "Buyer", not "Buyer 1").
     * Existing single-recipient callers see no behaviour change.
     */
    public static function mapSigningPartyKeys(array $keys, bool $isSales): array
    {
        $counts = array_count_values($keys);
        $running = [];
        return array_values(array_map(function ($k) use ($counts, &$running, $isSales) {
            $running[$k] = ($running[$k] ?? 0) + 1;
            $totalForRole = $counts[$k] ?? 1;
            return self::roleDisplayLabel($k, $isSales, $running[$k], $totalForRole);
        }, $keys));
    }

    /**
     * Display label for a single role token. When N > 1 instances of the
     * same role exist on this document, the label is suffixed with the
     * 1-based instance index ("Seller 2"). Singletons return the base
     * label only.
     *
     * B1 — used by Step 5's chip render (B4) and B2's per-instance block
     * headers. mapSigningPartyKeys() above delegates to this method.
     */
    public static function roleDisplayLabel(
        string $roleToken,
        bool $isSales,
        ?int $instanceIndex = null,
        int $totalInstancesForRole = 1,
    ): string {
        $map = $isSales
            ? ['owner_party' => 'Seller', 'acquiring_party' => 'Buyer', 'agent' => 'Agent']
            // Wizard-side aliases — see ESignWizardController $roleAliases. These
            // tokens land in signature_requests.party_role today.
            : ['owner_party' => 'Lessor', 'acquiring_party' => 'Lessee', 'agent' => 'Agent'];
        // Also recognise the wizard's raw tokens (seller / buyer / lessor / lessee /
        // landlord / tenant) so labels work whether the caller passes the canonical
        // owner_party/acquiring_party or the wizard's per-document-type token.
        $aliases = $isSales
            ? ['seller' => 'Seller', 'buyer' => 'Buyer']
            : ['lessor' => 'Lessor', 'lessee' => 'Lessee', 'landlord' => 'Lessor', 'tenant' => 'Lessee'];

        $base = $map[$roleToken]
            ?? $aliases[$roleToken]
            ?? ucfirst(str_replace('_', ' ', $roleToken));

        if ($totalInstancesForRole > 1 && $instanceIndex !== null) {
            return $base . ' ' . $instanceIndex;
        }
        return $base;
    }

    public function getPageImagesAttribute(): array
    {
        $urls = [];
        for ($n = 0; $n < $this->page_count; $n++) {
            $urls[] = route('docuperfect.page.image', ['id' => $this->id, 'page' => $n]);
        }
        return $urls;
    }

    /**
     * ES-5 — return the list of party-role tokens allowed to edit a given
     * tag at signing time.
     *
     * Reads from `field_mappings[tag_id].editable_by`. Returns an empty
     * array when the field is NOT editable at signing time (the field is
     * locked once the agent fills it during prep).
     *
     * Role tokens recognised:
     *   owner_party | acquiring_party | agent | witness | all
     *
     * Spec: .ai/specs/esign-v3-complete-spec.md §9
     */
    public function getEditableByForField(string $tagId): array
    {
        $mappings = $this->field_mappings ?? [];
        if (!is_array($mappings) || !isset($mappings[$tagId])) {
            return [];
        }
        $editableBy = $mappings[$tagId]['editable_by'] ?? null;
        if ($editableBy === null) {
            return [];
        }
        if (is_string($editableBy)) {
            // Legacy single-role string — normalise to array shape.
            return [$editableBy];
        }
        if (is_array($editableBy)) {
            return array_values(array_filter($editableBy, fn($r) => is_string($r) && $r !== ''));
        }
        return [];
    }

    /**
     * ES-5 — check whether a specific party role may edit a specific tag
     * at signing time. 'all' is a wildcard that matches every party.
     */
    public function isFieldEditableBy(string $tagId, string $partyRole): bool
    {
        $allowed = $this->getEditableByForField($tagId);
        if (empty($allowed)) {
            return false;
        }
        if (in_array('all', $allowed, true)) {
            return true;
        }
        return in_array($partyRole, $allowed, true);
    }
}
