<?php

namespace App\Models\Docuperfect;

use Illuminate\Support\Facades\Log;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 2, #7) — added
 * agency_id, but deliberately WITHOUT the BelongsToAgency trait. Unlike
 * Document/SignatureTemplate/SalesDocumentSend, templates can be
 * genuinely shared across every agency — the trait's automatic global
 * scope would hide a shared template whose agency_id is NULL (AgencyScope
 * treats a NULL agency_id row as an orphan, not "shared"). scopeVisibleTo(),
 * isVisibleToAgency() (used by TemplateController::webPreview()) and
 * assertAccessibleBy() implement the sharing check explicitly instead.
 *
 * 2026-09-01 (Johan) — "genuinely shared" means agency_id IS NULL, and
 * nothing else. It does NOT mean is_global=true: that flag is agency-internal
 * ("all my branches"), and reading it as platform-wide leaked two of HFC's
 * templates to every other agency on CoreX. applySharedWith() is now the one
 * definition all three of those paths share — never re-test `is_global` on
 * its own; opting out of the BelongsToAgency trait means this model's
 * isolation is hand-written, so a bare is_global check is a tenant leak.
 */
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
        'agency_id',
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
     *   1. cds_drafts row for this template (most recent, not deleted,
     *      belonging to the CURRENT viewer, and genuinely newer than
     *      this template's own last save). The builder writes to
     *      drafts continuously while the agent edits — if such a draft
     *      exists, it represents the most recent authored state.
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
     *   • `TemplateController::cdsGenerate()` flips this user's other
     *     superseded drafts for the same template to status='abandoned'
     *     on save (never soft-deletes on the hot path — see that
     *     method's docblock re: a8af5d10a).
     *
     * 2026-09-04 (Staging bug: "template save does not persist when
     * editing an existing template") — tier 1 used to match ANY
     * status='draft' row for this template, from ANY user, of ANY age,
     * with no comparison against this template's own last-saved state.
     * A single leftover abandoned draft (a different user's dead
     * session, or the same user's stray second tab) permanently
     * outranked every future fresh save, because nothing ever advanced
     * that row's `updated_at` and nothing ever excluded it. Fresh
     * imports never hit this because they have no editing history yet;
     * an existing template accumulates draft rows every time it's
     * opened, so one abandoned session was enough to shadow saves
     * forever. Fixed two ways, both required:
     *
     *   a) Scoped to `auth()->id()` — a draft only outranks the saved
     *      state for the person who actually owns it. With no
     *      authenticated user in scope (console/queue context) there is
     *      no "current in-progress session" to prefer, so tier 1 is
     *      skipped entirely rather than picking an arbitrary row.
     *   b) Gated on `updated_at >= $this->updated_at` — the draft must
     *      be genuinely newer than the template's own last save, not
     *      merely "the newest row that happens to exist". This is
     *      `updated_at` on `docuperfect_templates` itself, bumped by
     *      Eloquent on every `$template->save()`/`update()`, including
     *      non-content edits (rename, archive, branch sync doesn't
     *      touch it — pivot writes don't bump the parent row). That's
     *      safe here: a non-content save merely makes tier 1 fall
     *      through to tier 2 (editor_state), which is unchanged and
     *      therefore identical to what tier 1 would have returned
     *      anyway — never a wrong VALUE, only an unnecessary tier skip.
     *
     *   (a) alone is insufficient on its own — the same user's own
     *   stale draft (opened before their later save, never touched
     *   since) would still outrank that later save without (b).
     *
     * @return array<string, array<string, mixed>>
     */
    public function canonicalFieldMappings(): array
    {
        // Tier 1 — most recent IN-PROGRESS draft for THIS viewer, genuinely
        // newer than this template's last save. See docblock above.
        if (\Illuminate\Support\Facades\Schema::hasTable('cds_drafts')) {
            $currentUserId = \Illuminate\Support\Facades\Auth::id();
            if ($currentUserId !== null) {
                $draft = \Illuminate\Support\Facades\DB::table('cds_drafts')
                    ->where('source_template_id', $this->id)
                    ->where('user_id', $currentUserId)
                    ->where('status', 'draft')
                    ->whereNull('deleted_at')
                    ->where('updated_at', '>=', $this->updated_at)
                    ->orderByDesc('updated_at')
                    ->first();
                if ($draft !== null && !empty($draft->mappings)) {
                    $decoded = is_string($draft->mappings) ? json_decode($draft->mappings, true) : $draft->mappings;
                    if (is_array($decoded) && count($decoded) > 0) {
                        return $decoded;
                    }
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

    /**
     * Cross-agency isolation audit 2026-08-20 follow-up: TemplateController's
     * saveFields/uploadPageImages/archive/restore/webPreview/destroy/
     * saveWizardConfig, PageImageController::show, and
     * DocumentImporterController::editFromTemplate all did
     * `Template::findOrFail($id)` with only a hasPermission('manage_templates')
     * check (an ordinary, per-agency-grantable permission, not owner-only) --
     * any agency's admin/agent could read, rewrite, delete, or clone ANY other
     * agency's template by id. `docuperfect_templates` has no agency_id column
     * (tenancy is via `is_global` + the docuperfect_template_branches pivot,
     * since a branch belongs to exactly one agency) -- 404s rather than 403s
     * to match this pipeline's existing convention (SignatureController::
     * authorizeSignatureRequestForDocument).
     */
    public function assertAccessibleBy($user): void
    {
        if ($user->isOwnerRole()) {
            return;
        }
        $agencyId = $user->effectiveAgencyId();

        // A global template ignores branch narrowing — but ONLY inside the agency
        // that owns it. This used to be a bare `if ($this->is_global) return;`,
        // which handed every agency on the platform a direct-open on another
        // agency's template. isVisibleToAgency() now carries the ownership half
        // of that test; see its docblock and sharedWithAgency() below.
        if ($this->is_global && $this->isVisibleToAgency($agencyId)) {
            return;
        }

        // 2026-08-24 mismatch fix: a template with branches assigned is
        // explicitly narrowed to those branches -- honor that exactly as
        // before. A template with NO branches assigned is not "narrowed to
        // nothing" -- it falls back to the same is_global-aware agency match
        // scopeVisibleTo() and isVisibleToAgency() already use for listing.
        // Before this fix the two disagreed: a branch-less, agency-matching
        // template was LISTED (scopeVisibleTo) but 404'd here the instant it
        // was opened -- the exact shape that stranded every template created
        // via the PDF-upload and .docx-import paths (neither links a branch).
        if ($this->branches()->exists()) {
            if ($agencyId && $this->branches()->where('branches.agency_id', $agencyId)->exists()) {
                return;
            }
            abort(404);
        }

        if ($this->isVisibleToAgency($agencyId)) {
            return;
        }

        abort(404);
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

        // 2026-08-15 — 'all' used to mean "every template on the entire
        // platform," not "every template my agency can see." Now: this
        // agency's own templates, plus anything genuinely global.
        if ($scope === 'all') {
            $agencyId = method_exists($user, 'effectiveAgencyId') ? $user->effectiveAgencyId() : $user->agency_id;
            // AT-390 — a data-scope 'all' user with NO agency of their own (a
            // platform super_admin) has no "own agency" for the clause below to
            // narrow to. The 2026-08-15 rewrite above didn't account for that
            // case, so the query silently collapsed to is_global-only (2 rows
            // platform-wide) instead of "all" meaning all, as it did before
            // that change. Confirmed: Johan's own super_admin account (agency_id
            // NULL) saw a completely blank Template Management list on Staging.
            if (! $agencyId) {
                return $query;
            }
            return $query->where(function ($q) use ($agencyId) {
                self::applySharedWith($q, $agencyId)->orWhere('agency_id', $agencyId);
            });
        }

        $agencyId = $user->effectiveAgencyId();
        $branchId = $user->effectiveBranchId();

        return $query->where(function ($q) use ($agencyId, $branchId) {
            self::applySharedWith($q, $agencyId);
            if ($branchId) {
                $q->orWhereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                });
            }
        });
    }

    /**
     * The `is_global` term, written correctly — the single definition every
     * visibility path in this model shares.
     *
     * 2026-09-01 (Johan, cross-agency leak on /docuperfect/create and
     * /docuperfect/templates) — `is_global` was being read as "visible to the
     * entire platform" everywhere it appeared, with nothing anywhere checking
     * WHO OWNS the row. Two of Home Finders Coastal's own templates carry
     * is_global=1 (#74 "Sales Mandatory Disclosure", #75 "HFC Addendum B"), so
     * every user of every other agency on CoreX was shown them — reproduced on
     * production against Demo Agency Test, which saw exactly those two and
     * nothing else of its own.
     *
     * What the flag actually means, and now enforces: `is_global` is an
     * AGENCY-INTERNAL "every branch, not just the branches I listed" flag. A
     * template reaches beyond its agency only when it belongs to no agency at
     * all (agency_id IS NULL) — a genuine platform template shipped by CoreX.
     * That reading keeps an agency's own global templates working exactly as
     * they always have for that agency's users (HFC still sees #74 and #75 on
     * every branch) while making cross-agency reach structurally impossible
     * rather than a matter of remembering to filter.
     *
     * $agencyId null = a platform user with no agency of their own; only the
     * genuinely-ownerless globals are shared with them.
     */
    public static function applySharedWith($query, ?int $agencyId)
    {
        return $query->where(function ($g) use ($agencyId) {
            $g->where('is_global', true)
              ->where(function ($owner) use ($agencyId) {
                  $owner->whereNull('agency_id');
                  if ($agencyId) {
                      $owner->orWhere('agency_id', $agencyId);
                  }
              });
        });
    }

    /**
     * Direct-id-lookup guard for callers (TemplateController::webPreview())
     * that fetch a Template by id outside scopeVisibleTo() — the same rule
     * applySharedWith() applies in SQL: a global template is reachable only
     * from the agency that owns it (or from anywhere when it belongs to nobody),
     * and a non-global one only from its own agency.
     */
    public function isVisibleToAgency(?int $agencyId): bool
    {
        if ($this->is_global && $this->agency_id === null) {
            return true;
        }

        return $agencyId !== null && (int) $this->agency_id === $agencyId;
    }

    public function isPerParty(): bool
    {
        return $this->party_mode === 'per_party';
    }

    /**
     * The genuinely-determinable half of the sales/rental question —
     * explicit signing_parties roles, the builder's own category/
     * template_type, or the property-source table. Returns null when NONE
     * of those give a real signal, rather than guessing from the
     * template's name. A caller that renders something to a human (party
     * labels) MUST treat null as "unknown — ask the user to set
     * Template::category" and MUST NOT silently default to either sales or
     * rental. isSalesDocument() below still falls back to a name guess for
     * its own long-standing bool-only callers; new/label-rendering callers
     * should prefer THIS method instead. See ESIGN-WETINK.md for the bug
     * this fixes: a CDS-imported template (category/document_type_id never
     * set by the importer) with a name carrying no sales keyword ("Mandatory
     * Disclosure", "Addendum B") was rendering confidently, but wrongly, as
     * a rental document.
     */
    public function resolvedTransactionCategory(?string $propertySource = null): ?string
    {
        // Layer 1: check signing_parties for explicit sales/rental roles
        $parties = $this->signing_parties ?? [];
        if (is_array($parties) && !empty($parties)) {
            $roles = array_map('strtolower', $parties);
            $hasSales = !empty(array_intersect($roles, ['seller', 'buyer']));
            $hasRental = !empty(array_intersect($roles, ['landlord', 'tenant', 'lessor', 'lessee']));
            if ($hasSales && !$hasRental) return 'sales';
            if ($hasRental && !$hasSales) return 'rentals';
        }

        // Layer 2: explicit category / template_type set by the builder.
        // Authoritative — covers CDS templates whose signing_parties use the
        // generic owner_party/acquiring_party tokens (so Layer 1 can't tell)
        // and whose name carries no sales keyword. template_type 'cds' is
        // neutral and skipped (no sale/rent substring), so category decides.
        foreach ([strtolower((string) ($this->category ?? '')), strtolower((string) ($this->template_type ?? ''))] as $sig) {
            if ($sig === '') continue;
            if (str_contains($sig, 'sale') || $sig === 'otp') return 'sales';
            if (str_contains($sig, 'rent') || str_contains($sig, 'lett') || str_contains($sig, 'lease')) return 'rentals';
        }

        // Layer 3: property source table
        if ($propertySource === 'properties') return 'sales';
        if ($propertySource === 'rental_properties') return 'rentals';

        return null;
    }

    /**
     * Detect if this is a sales-context document.
     * Layered: explicit signing_parties roles > category/template_type >
     * property source > name pattern matching (last resort).
     * Accepts optional $propertySource ('properties' or 'rental_properties') for step-data context.
     *
     * Kept for existing bool-contract callers that must always receive a
     * definite answer (dashboard routing, wizard document_context, etc.).
     * Party-LABEL rendering must use resolvedTransactionCategory() instead
     * and treat null explicitly — see TemplateController::generateCdsBladeView().
     */
    public function isSalesDocument(?string $propertySource = null): bool
    {
        $determined = $this->resolvedTransactionCategory($propertySource);
        if ($determined !== null) {
            return $determined === 'sales';
        }

        // Layer 4: template name pattern matching (last-resort fallback) —
        // ONLY reached here, for this legacy bool-only contract. Never used
        // by party-label rendering.
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
     * Layered defence — with an honest note on what each layer really does:
     *
     *   Layer 1 — document_type slug (`document_types.slug`). **LIVE, and the strongest.**
     *             Five live OTPs are blocked by it today. It is the only layer that survives a
     *             RENAME, because it asks what the document IS, not what it is called.
     *             (Correction: an earlier version of this docblock called Layer 1 dead. That was
     *             wrong — it was read against `docuperfect_document_types`, a legacy table with
     *             no slug. The FK points at `document_types`, which has slugs for all five
     *             blocked types. The layer was never dead; nothing was ever CLASSIFIED.)
     *   Layer 2 — template_type string. Effectively inert: the values in the wild are
     *             `sales` / `rental` / `standard` / `general`, none of which is a blocked slug.
     *   Layer 3 — name regex. The fallback for anything unclassified. Load-bearing precisely
     *             because classification was missing — "Contract of Sale" is on live today and
     *             the old pattern did NOT match it.
     *   Layer 4 — the saving guard above: a blocked template cannot be STORED e-signable, by
     *             any of the seven writers of `is_esign`.
     *   Layer 5 — every trigger writes to legal_block_audit_log (insert-only).
     *
     * The real fix was never a longer regex — it was classifying documents at all.
     * `DocumentTypeClassifier` now classifies on import, and a migration backfills the
     * templates nobody ever classified. A classified sale stays blocked whatever it is renamed.
     *
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
    public static function mapSigningPartyKeys(array $keys, ?bool $isSales): array
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
        ?bool $isSales,
        ?int $instanceIndex = null,
        int $totalInstancesForRole = 1,
    ): string {
        // $isSales === null means the template's sales/rental nature is
        // genuinely undetermined (see Template::resolvedTransactionCategory()).
        // Do NOT guess Seller/Buyer or Lessor/Lessee in that case — fall
        // through to the raw-token label below, which is honest about not
        // knowing rather than confidently wrong.
        $map = $isSales === null
            ? []
            : ($isSales
                ? ['owner_party' => 'Seller', 'acquiring_party' => 'Buyer', 'agent' => 'Agent']
                // Wizard-side aliases — see ESignWizardController $roleAliases. These
                // tokens land in signature_requests.party_role today.
                : ['owner_party' => 'Lessor', 'acquiring_party' => 'Lessee', 'agent' => 'Agent']);
        // Also recognise the wizard's raw tokens (seller / buyer / lessor / lessee /
        // landlord / tenant) so labels work whether the caller passes the canonical
        // owner_party/acquiring_party or the wizard's per-document-type token.
        $aliases = $isSales === null
            ? []
            : ($isSales
                ? ['seller' => 'Seller', 'buyer' => 'Buyer']
                : ['lessor' => 'Lessor', 'lessee' => 'Lessee', 'landlord' => 'Lessor', 'tenant' => 'Lessee']);

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
