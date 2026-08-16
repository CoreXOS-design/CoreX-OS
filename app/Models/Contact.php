<?php

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Scopes\ContactScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Contact extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    // Entity-type foundation (.ai/specs/contact-entity-type.md) — coarse
    // natural-person/entity split, distinct from fica_submissions.entity_type's
    // richer natural/company/trust/partnership classification.
    public const TYPE_NATURAL_PERSON = 'natural_person';
    public const TYPE_ENTITY         = 'entity';

    protected static function booted(): void
    {
        static::addGlobalScope(new ContactScope());
    }

    protected $fillable = [
        // agency_id is the tenant key. Fillable so trusted non-auth ingress
        // (webhooks, imports) can stamp it, but an AUTHENTICATED user can never
        // spoof it — BelongsToAgency::creating() force-overrides it to the user's
        // effective agency. See that trait.
        'agency_id',
        'branch_id',
        'contact_type_id', 'contact_source_id', 'created_by_user_id',
        'agent_id', 'second_agent_id',
        'client_user_id',
        'first_name', 'last_name', 'phone', 'email', 'notes',
        'birthday', 'birthday_reminder', 'id_number', 'id_type', 'id_number_captured_at', 'id_number_source', 'address',
        // Entity-type foundation (.ai/specs/contact-entity-type.md) —
        // 'contact_kind' is deliberately coarser than fica_submissions.
        // entity_type; it only distinguishes natural_person vs entity for
        // linking/ownership/dedup. NOT named 'type' — that column name
        // shadowed the pre-existing Contact::type() relationship
        // (belongsTo(ContactType::class)), see the incident-fix migration
        // 2026_08_21_000100_rename_type_to_contact_kind_on_contacts_table.
        'contact_kind', 'entity_name', 'entity_reg_no',
        // AT-60 — structured PROPERTY-address capture (independent of the
        // residential `address` above; never auto-composed into it).
        'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
        'street_number', 'street_name', 'suburb', 'city', 'province',
        'p24_province_id', 'p24_city_id', 'p24_suburb_id',
        'loaded_at', 'modified_at', 'last_contacted_at', 'contacted_marked_at',
        'whatsapp_count', 'email_count',
        'bank_name', 'bank_account_name', 'bank_account_number',
        'bank_branch_name', 'bank_branch_code', 'bank_account_type',
        'opt_out_email', 'opt_out_sms', 'opt_out_whatsapp', 'opt_out_call',
        'last_consent_check_at',
        'is_buyer', 'buyer_state', 'last_activity_at',
        'buyer_pipeline_entered_at', 'buyer_pipeline_notes', 'buyer_source',
        'preapproval_amount', 'preapproval_expires_at', 'preapproval_institution',
        'messaging_opt_out_at', 'messaging_opt_out_reason', 'messaging_opt_out_recorded_by_user_id', 'messaging_opt_out_source',
        'messaging_opt_out_kind', // AT-81 — declined | no_response sub-state
        'messaging_all_blocked',
        'messaging_opted_in_at', 'messaging_opt_in_reason', 'messaging_opt_in_recorded_by_user_id',
        'outreach_permission_asked_at', // AT-81 — PENDING marker + no-response clock
    ];

    protected $casts = [
        'birthday'              => 'date',
        'birthday_reminder'     => 'boolean',
        'id_number_captured_at' => 'datetime',
        'loaded_at'             => 'datetime',
        'modified_at'       => 'datetime',
        'last_contacted_at' => 'datetime',
        'contacted_marked_at' => 'datetime', // AT-372 — explicit "contacted" signal
        'is_buyer'          => 'boolean',
        'last_activity_at'  => 'datetime',
        'buyer_pipeline_entered_at' => 'datetime',
        'preapproval_amount'        => 'decimal:2',
        'preapproval_expires_at'    => 'date',
        'messaging_opt_out_at'      => 'datetime',
        'messaging_all_blocked'     => 'boolean',
        'messaging_opted_in_at'     => 'datetime',
        'outreach_permission_asked_at' => 'datetime', // AT-81
    ];

    /**
     * True iff the contact has a non-zero preapproval amount and the
     * preapproval has not expired. Used by demand-intelligence queries
     * (PropertyMatchScoringService::getBuyerDemandForProperty).
     */
    public function hasValidPreapproval(): bool
    {
        if ($this->preapproval_amount === null || (float) $this->preapproval_amount <= 0) {
            return false;
        }
        if ($this->preapproval_expires_at === null) {
            return false;
        }
        return $this->preapproval_expires_at->isToday()
            || $this->preapproval_expires_at->isFuture();
    }

    /**
     * AT-321-C — the SANCTIONED way to make a "quiet" contact write and still keep
     * the audit trail. It suppresses the observer (updateQuietly) — so no side
     * effects — but records a rich, attributed audit row itself, and de-dupes the
     * DB backstop trigger. Use this instead of a raw ->updateQuietly() / DB::table
     * update whenever the change is meaningful. The dev-check gate points call sites
     * here.
     *
     * @param array<string, mixed> $attrs  column => new value
     */
    public function auditedQuietUpdate(
        array $attrs,
        string $eventType = 'contact_updated',
        ?string $summary = null,
        ?array $metadata = null,
        ?\App\Models\User $actor = null,
    ): bool {
        $old = [];
        foreach (array_keys($attrs) as $col) {
            $old[$col] = $this->getOriginal($col) ?? $this->{$col};
        }

        \App\Support\Audit\AuditContext::markHandled();
        try {
            $result = $this->updateQuietly($attrs);
        } finally {
            \App\Support\Audit\AuditContext::clearHandled();
        }

        app(\App\Services\Audit\ContactAuditService::class)->log(
            $this, 'contact', $eventType, $actor,
            oldValues: $old,
            newValues: $attrs,
            metadata: $metadata ?? ['fields' => array_keys($attrs)],
            humanSummary: $summary ?? ('Updated ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), array_keys($attrs)))),
        );

        return $result;
    }

    /** AT-321-C — the contact's audit trail (newest first for the History tab). */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ContactAuditLog::class)->orderByDesc('created_at');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContactType::class, 'contact_type_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ContactSource::class, 'contact_source_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ContactTag::class, 'contact_tag')
                    ->withTimestamps();
    }

    /**
     * The parent contact types this contact belongs to (AT-79 multi-parent
     * model). A person can be Seller AND Buyer. The single `type()`/
     * contact_type_id above is a denormalised "primary parent" mirror kept in
     * sync by syncTypeAssignments() for legacy readers + e-sign reverse-mapping.
     */
    public function parentTypes(): BelongsToMany
    {
        return $this->belongsToMany(ContactType::class, 'contact_contact_type')
                    ->withTimestamps();
    }

    /**
     * Single source of truth for writing a contact's type/tag assignments.
     * Syncs the multi-parent pivot and the sub-tag pivot, then re-derives the
     * primary-parent mirror (contacts.contact_type_id = lowest-sort assigned
     * parent, or null). Returns the tag IDs newly attached this call so the
     * caller can fire ContactTagged events.
     *
     * @param  int[]  $parentTypeIds  parent contact_type IDs to assign
     * @param  int[]  $tagIds         sub-tag IDs to assign
     * @return int[]  newly-attached tag IDs
     */
    public function syncTypeAssignments(array $parentTypeIds, array $tagIds): array
    {
        $parentTypeIds = array_values(array_unique(array_map('intval', $parentTypeIds)));
        $tagIds        = array_values(array_unique(array_map('intval', $tagIds)));

        // A chosen sub-tag implies its parent — fold those parents in so parent
        // membership is never implicit-only.
        if (!empty($tagIds)) {
            $impliedParents = ContactTag::whereIn('id', $tagIds)
                ->whereNotNull('contact_type_id')
                ->pluck('contact_type_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $parentTypeIds = array_values(array_unique(array_merge($parentTypeIds, $impliedParents)));
        }

        $previousTagIds = $this->tags()->pluck('contact_tags.id')->map(fn ($id) => (int) $id)->all();

        $this->parentTypes()->sync($parentTypeIds);
        $this->tags()->sync($tagIds);

        // Re-derive the primary mirror: lowest-sort parent currently assigned.
        $primaryId = empty($parentTypeIds) ? null : ContactType::whereIn('id', $parentTypeIds)
            ->orderBy('sort_order')->orderBy('id')
            ->value('id');

        if ((int) $this->contact_type_id !== (int) $primaryId) {
            $this->contact_type_id = $primaryId;
            $this->save();
        }

        return array_values(array_diff($tagIds, $previousTagIds));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Operational primary agent on this contact (reassignable). */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** Optional co-agent on this contact. */
    public function secondAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_agent_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function hasClientLogin(): bool
    {
        return $this->client_user_id !== null;
    }

    public function contactNotes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->latest();
    }

    public function testimonials(): HasMany
    {
        return $this->hasMany(ContactTestimonial::class)->latest();
    }

    /**
     * MIC ↔ Deeds ↔ Contact loop (Part B) — the "No contact details available" dead-end marker,
     * if this contact has been recorded as a dead end (nothing contactable). One active flag.
     */
    public function deadEndFlag(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContactDeadEndFlag::class);
    }

    /** @deprecated Use documents() instead. Kept for backward compat during transition. */
    public function legacyDocuments(): HasMany
    {
        return $this->hasMany(ContactDocument::class)->latest();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_contacts')
            ->withPivot('party_role')
            ->withTimestamps()
            ->latest('documents.created_at');
    }

    /**
     * Signed e-signature documents linked to this contact via pivot.
     */
    public function signedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Docuperfect\Document::class,
            'document_contact',
            'contact_id',
            'document_id'
        )->withPivot(['party_role', 'document_type', 'is_signed', 'signed_at', 'signed_pdf_path'])
         ->withTimestamps();
    }

    /**
     * Get FICA documents for this contact (legacy e-sign pivot).
     */
    public function ficaDocuments(): BelongsToMany
    {
        return $this->signedDocuments()
            ->wherePivot('document_type', 'fica')
            ->wherePivot('is_signed', true);
    }

    /**
     * FICA submissions linked to this contact (new standalone FICA form system).
     */
    public function ficaSubmissions(): HasMany
    {
        return $this->hasMany(FicaSubmission::class)->latest();
    }

    /**
     * Check FICA compliance status.
     * Checks both legacy e-sign FICA docs AND the new fica_submissions table.
     * Returns: 'complete', 'expiring', 'incomplete'
     */
    public function ficaStatus(): string
    {
        // Check new FICA submission system first
        $approvedSubmission = $this->ficaSubmissions()
            ->where('status', 'approved')
            ->orderByDesc('verified_at')
            ->first();

        if ($approvedSubmission) {
            $verifiedAt = $approvedSubmission->verified_at;
            if ($verifiedAt && $verifiedAt->diffInMonths(now()) >= 11) {
                return 'expiring';
            }
            return 'complete';
        }

        // Fall back to legacy e-sign FICA documents
        $ficaDocs = $this->ficaDocuments()->get();
        if ($ficaDocs->isEmpty()) {
            return 'incomplete';
        }
        $latest = $ficaDocs->sortByDesc('pivot.signed_at')->first();
        if ($latest && $latest->pivot->signed_at) {
            $signedAt = \Carbon\Carbon::parse($latest->pivot->signed_at);
            if ($signedAt->diffInMonths(now()) >= 11) {
                return 'expiring';
            }
            return 'complete';
        }
        return 'complete';
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ContactMatch::class)->latest();
    }

    // ── AT-125 — multiple phones / emails per contact ──
    // contacts.phone/email remain the synced-primary MIRROR (kept correct by
    // ContactIdentifierService via the ContactPhone/ContactEmail observers).

    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class);
    }

    /** The single primary phone (the one mirrored into contacts.phone), if any. */
    public function primaryPhone(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContactPhone::class)->where('is_primary', true);
    }

    /** The single primary email (the one mirrored into contacts.email), if any. */
    public function primaryEmail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContactEmail::class)->where('is_primary', true);
    }

    /**
     * Contact-details Phase 3 — the number outreach should use for WhatsApp,
     * independent of primaryPhone(). Falls back to primaryPhone() at the call
     * site (this relation itself returns null if no number is flagged
     * is_primary_whatsapp) — see WhatsAppNumberFormatter call sites.
     */
    public function primaryWhatsAppPhone(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContactPhone::class)->where('is_primary_whatsapp', true);
    }

    /**
     * THE number every WhatsApp click-to-chat builder should use: the
     * designated primary-WhatsApp number if one exists, else the primary
     * CONTACT number (the pre-Phase-3 behaviour every existing contact still
     * gets, since most have no WhatsApp designation at all).
     */
    public function whatsAppPhone(): ?ContactPhone
    {
        return $this->primaryWhatsAppPhone ?? $this->primaryPhone;
    }

    // ── AT-131 — THE canonical contact search/result pair (mirrors AT-128's
    //    Property::scopeSearchAddress + toSearchResult). Every contact picker uses
    //    these so the search can never drift bespoke again. ──

    /**
     * Canonical contact search: multi-term token AND across name + id_number + ALL
     * identifiers (the AT-125 child tables contact_phones/contact_emails, not just
     * the mirror) — so a contact reachable only by a SECONDARY phone/email is now
     * findable. Each whitespace token must match SOME field; relevance-ordered
     * (exact > prefix > contains on name) with newest-first as the tiebreak.
     * AgencyScope + SoftDeletes apply via the relations (multi-tenant safe).
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $like   = '%' . $token . '%';
            $lcLike = '%' . mb_strtolower($token) . '%';

            // Only treat the token as a phone-number fragment when it actually
            // LOOKS like one (digits + common phone punctuation only, nothing
            // else). Extracting digits from an arbitrary alphanumeric token —
            // an email like "roets12@gmail.com", a unit number like "12B" —
            // turns a 1-2 digit fragment into a near-useless filter that
            // matches most of the phone book. Confirmed in production
            // 2026-08-13: searching an email containing "12" matched 519
            // unrelated contacts via phone_normalised LIKE '%12%', burying
            // the real result many pages deep.
            $strippedPunct = preg_replace('/[\s\-()+]/', '', $token);
            $digits = ($strippedPunct !== '' && ctype_digit($strippedPunct)) ? $strippedPunct : '';

            $query->where(function ($q) use ($like, $lcLike, $digits) {
                $q->where('first_name', 'like', $like)
                  ->orWhere('last_name', 'like', $like)
                  ->orWhere('id_number', 'like', $like)
                  // mirror columns — fast path / belt
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  // ALL emails (child table) — closes the AT-125 secondary-identifier gap
                  ->orWhereHas('emails', fn ($e) => $e->where('email', 'like', $like)
                                                      ->orWhere('email_normalised', 'like', $lcLike))
                  // ALL phones (child table): match as-typed + the normalised key on
                  // the typed digits (strip leading 0 so "082…" finds last-9 "82…").
                  ->orWhereHas('phones', function ($p) use ($like, $digits) {
                      $p->where('phone', 'like', $like);
                      if ($digits !== '') {
                          $p->orWhere('phone_normalised', 'like', '%' . ltrim($digits, '0') . '%');
                      }
                  });
            });
        }

        $lower    = mb_strtolower($term);
        $prefix   = $lower . '%';
        $fullName = "LOWER(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
        $query->orderByRaw(
            "CASE
                WHEN LOWER(first_name) = ? OR LOWER(last_name) = ? OR {$fullName} = ? THEN 0
                WHEN LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR {$fullName} LIKE ? THEN 1
                ELSE 2
            END",
            [$lower, $lower, $lower, $prefix, $prefix, $prefix]
        )->orderByDesc('created_at'); // newest-first tiebreak (CoreX never deletes)

        return $query;
    }

    /**
     * Canonical contact-picker result row: name + the identifier that MATCHED the
     * query (so a secondary phone/email match is shown, not the primary) + contact
     * type + assigned agent. $extra merges surface-specific fields (e.g. ESign bank
     * data) without re-implementing the base. Eager-load phones/emails/type/agent.
     */
    public function toSearchResult(?string $term = null, array $extra = []): array
    {
        $name = trim((string) $this->first_name . ' ' . (string) $this->last_name);

        return array_merge([
            'id'         => $this->id,
            'label'      => $name !== '' ? $name : '(no name)',
            'identifier' => $this->matchedIdentifier($term),
            'type'       => $this->type?->name,
            'agent'      => $this->agent?->name,
        ], $extra);
    }

    /**
     * The identifier that matched the search term (id_number / phone / email),
     * falling back to the primary phone then primary email then the mirror. Lets a
     * picker show WHICH secondary identifier hit, so disambiguation is obvious.
     */
    public function matchedIdentifier(?string $term = null): ?string
    {
        $term = trim((string) $term);
        if ($term !== '') {
            $lc     = mb_strtolower($term);
            $digits = preg_replace('/\D/', '', $term);

            if ($this->id_number && str_contains(mb_strtolower((string) $this->id_number), $lc)) {
                return 'ID ' . $this->id_number;
            }
            if ($digits !== '') {
                foreach ($this->phones as $p) {
                    if (str_contains(preg_replace('/\D/', '', (string) $p->phone), $digits)) {
                        return (string) $p->phone;
                    }
                }
            }
            foreach ($this->emails as $e) {
                if (str_contains(mb_strtolower((string) $e->email), $lc)) {
                    return (string) $e->email;
                }
            }
        }

        return $this->primaryPhone?->phone
            ?? $this->primaryEmail?->email
            ?? $this->phone
            ?? $this->email;
    }

    /**
     * AT-74 — does this buyer have at least one COUNTABLE wishlist (AT-71
     * isCountable())? A pipeline buyer with zero countable wishlists (criteria
     * removed / only an empty wishlist) correctly STAYS on the pipeline but is
     * excluded from every match figure — the "No core match" tag surfaces this.
     *
     * Uses the loaded `matches` relation when eager-loaded (no N+1); otherwise
     * lazy-loads it. SoftDeletes means a deleted wishlist is already excluded.
     */
    public function hasCountableWishlist(): bool
    {
        return $this->matches->contains(fn (ContactMatch $m) => $m->isCountable());
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'contact_property')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    /**
     * Street & Complex Search (AT-273) — an ADDRESS-ONLY search, deliberately
     * distinct from scopeSearch (which is name/id/phone/email). It matches ONLY
     * the two address surfaces a user thinks of as "where this contact is":
     *
     *   1. the contact's own Address — the residential free-text `address`
     *      column PLUS the structured property-address components
     *      (complex_name / street_name / suburb / city); and
     *   2. the contact's Linked Properties — the same address surfaces on any
     *      `Property` joined through the contact_property pivot.
     *
     * Multi-token AND: every whitespace-separated token must hit at least one
     * surface, so "marine estate" finds a contact only when BOTH words appear
     * somewhere in its address graph — never everything matching either word.
     * Name / phone / email are intentionally NOT searched here.
     */
    public function scopeStreetComplexSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $like = '%' . $token . '%';

            $query->where(function ($q) use ($like) {
                // The contact's own address surfaces.
                $q->where('address', 'like', $like)
                  ->orWhere('complex_name', 'like', $like)
                  ->orWhere('street_name', 'like', $like)
                  ->orWhere('suburb', 'like', $like)
                  ->orWhere('city', 'like', $like)
                  // Linked Properties — the property's address surfaces.
                  ->orWhereHas('properties', function ($p) use ($like) {
                      $p->where('complex_name', 'like', $like)
                        ->orWhere('street_name', 'like', $like)
                        ->orWhere('suburb', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('address', 'like', $like)
                        ->orWhere('title', 'like', $like);
                  });
            });
        }

        // Ordering is applied by the caller (ContactController::applyStreetComplexSort)
        // so the results page and its PDF can share one sort selection.
        return $query;
    }

    public function getFullNameAttribute(): string
    {
        if ($this->contact_kind === self::TYPE_ENTITY) {
            return (string) $this->entity_name;
        }

        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function isEntity(): bool
    {
        return $this->contact_kind === self::TYPE_ENTITY;
    }

    /**
     * The natural-person Contacts who represent THIS entity Contact (director/
     * trustee/partner/signatory). Many-to-many: a director can sit on multiple
     * entities. Spec: .ai/specs/contact-entity-type.md §4.2/§5.
     *
     * wherePivotNull('deleted_at') is load-bearing: ContactRepresentative
     * extends Pivot + SoftDeletes, so detach() (via ->using()) soft-deletes
     * the pivot row rather than hard-deleting it (Non-Negotiable #1) — without
     * this filter an "unlinked" representative would still show as linked.
     */
    public function representatives(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'contact_representatives',
            'entity_contact_id',
            'representative_contact_id'
        )->using(ContactRepresentative::class)->withPivot('is_primary')->withTimestamps()->wherePivotNull('deleted_at');
    }

    /**
     * The entity Contacts THIS natural-person Contact represents (inverse of
     * representatives()).
     */
    public function representedEntities(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'contact_representatives',
            'representative_contact_id',
            'entity_contact_id'
        )->using(ContactRepresentative::class)->withPivot('is_primary')->withTimestamps()->wherePivotNull('deleted_at');
    }

    /**
     * DERIVED "company properties" (property → company → director), for the
     * entity model Johan approved (2026-08-14). Returns the properties owned by
     * any company (entity) THIS natural-person contact represents as a director,
     * each flagged "Company property · via {Company}" so a display can keep them
     * DISTINCT from properties the person owns PERSONALLY (a direct
     * contact_property link). Covers both promoted agency-stock Properties
     * (contact_property role=owner on the company) and un-promoted tracked
     * properties (tracked_property_owners.contact_id = the company).
     *
     * This is a READ-ONLY derivation — no ownership is ever written onto the
     * director; the canonical single owner stays the company.
     *
     * @return \Illuminate\Support\Collection<int, array{kind:string, property:mixed, company_contact_id:int, company_name:string, flag:string}>
     */
    public function companyPropertiesViaDirectorship(): \Illuminate\Support\Collection
    {
        $out = collect();

        foreach ($this->representedEntities()->get() as $company) {
            $companyName = $company->full_name;
            $flag = 'Company property · via ' . $companyName;

            // Promoted agency-stock Properties owned by the company.
            foreach ($company->properties()->wherePivot('role', 'owner')->get() as $property) {
                $out->push([
                    'kind'               => 'property',
                    'property'           => $property,
                    'company_contact_id' => (int) $company->id,
                    'company_name'       => $companyName,
                    'flag'               => $flag,
                ]);
            }

            // Un-promoted tracked properties owned by the company (CMA/deeds).
            $tracked = \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereHas('owners', fn ($q) => $q->where('contact_id', $company->id))
                ->get();
            foreach ($tracked as $tp) {
                $out->push([
                    'kind'               => 'tracked_property',
                    'property'           => $tp,
                    'company_contact_id' => (int) $company->id,
                    'company_name'       => $companyName,
                    'flag'               => $flag,
                ]);
            }
        }

        return $out->values();
    }

    /** Normalized "street_number street_name" — the display-dedupe address key. */
    public static function normalizePropertyStreet(?string $number, ?string $street): string
    {
        $s = trim(($number ?? '') . ' ' . ($street ?? ''));
        return strtolower((string) preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Identity keys of properties that are COMPANY-OWNED-VIA-DIRECTORSHIP for
     * this contact — used to DEDUPE the display so such a property shows ONLY in
     * the flagged "Company Properties" group, never also as a personal Linked
     * Property. Returns canonical Property ids AND normalized street addresses:
     * the address key bridges the tracked-vs-promoted split (the same physical
     * property can exist as a tracked_property and a promoted Property with
     * different ids). Read-only — the contact_property link is untouched
     * (outreach still needs it).
     *
     * @return array{ids: array<int>, addresses: array<string>}
     */
    public function companyPropertyDedupeKeys(): array
    {
        $ids = [];
        $addresses = [];
        foreach ($this->companyPropertiesViaDirectorship() as $row) {
            $prop = $row['property'];
            if ($row['kind'] === 'property') {
                $ids[] = (int) $prop->id;
            } elseif (!empty($prop->promoted_to_property_id)) {
                $ids[] = (int) $prop->promoted_to_property_id;
            }
            $addr = self::normalizePropertyStreet($prop->street_number ?? null, $prop->street_name ?? null);
            if ($addr !== '') {
                $addresses[] = $addr;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'addresses' => array_values(array_unique($addresses))];
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(substr($this->first_name, 0, 1) . substr($this->last_name, 0, 1));
    }

    // ── Structured PROPERTY-address capture (AT-60) ──────────────────────
    //
    // These columns are a property-creation aid ("capture an address → start a
    // new property"), edited on the Properties & Core Matches tab. They are
    // INDEPENDENT of the contact's residential `address` (the free-text Info
    // field) and NEVER write to it.

    /**
     * True iff ANY structured property-address component is populated. Drives
     * whether the "Use for property" transfer button shows.
     */
    public function hasStructuredAddress(): bool
    {
        foreach ([
            'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
            'street_number', 'street_name', 'suburb', 'city', 'province',
        ] as $field) {
            if (filled($this->{$field})) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compose a single denormalised display string from the structured
     * property-address components, mirroring Property::buildDisplayAddress.
     * Returns null when no component is set. Used by the duplicate-address
     * guard (token-overlap fallback) and as a display convenience — it does
     * NOT touch the residential `address` field.
     */
    public function composeStructuredAddress(): ?string
    {
        if (! $this->hasStructuredAddress()) {
            return null;
        }

        $parts = [];

        if (filled($this->unit_number)) {
            $parts[] = 'Unit ' . trim((string) $this->unit_number);
        }
        if (filled($this->unit_section_block)) {
            $parts[] = trim((string) $this->unit_section_block);
        }
        if (filled($this->complex_name)) {
            $parts[] = trim((string) $this->complex_name);
        }

        if (filled($this->street_number) && filled($this->street_name)) {
            $parts[] = trim($this->street_number . ' ' . $this->street_name);
        } elseif (filled($this->street_name)) {
            $parts[] = trim((string) $this->street_name);
        }

        if (filled($this->suburb)) {
            $parts[] = trim((string) $this->suburb);
        }
        if (filled($this->city) && strtolower((string) $this->city) !== strtolower((string) ($this->suburb ?? ''))) {
            $parts[] = trim((string) $this->city);
        }
        if (filled($this->province)) {
            $parts[] = trim((string) $this->province);
        }

        $composed = trim(implode(', ', array_filter($parts, 'strlen')));

        return $composed !== '' ? $composed : null;
    }

    // ── Consent & Compliance (M3.4) ──

    /**
     * The 7 consent types and their display labels — the single source shared by
     * the agent web tab, agent-mobile API, and client-mobile API. Spec:
     * .ai/specs/contact-consent.md §3.
     */
    public const CONSENT_TYPES = [
        'fica_processing'          => 'FICA Processing',
        'marketing_communications' => 'Marketing Communications',
        'data_sharing'             => 'Data Sharing',
        'channel_email'            => 'Email',
        'channel_sms'              => 'SMS',
        'channel_whatsapp'         => 'WhatsApp',
        'channel_call'             => 'Phone Call',
    ];

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ContactConsentRecord::class)->latest('given_at');
    }

    public function hasActiveConsent(string $consentType): bool
    {
        return $this->consentRecords()
            ->where('consent_type', $consentType)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * The contact's current decision for a consent type:
     *   'given'    — agreed
     *   'declined' — explicitly refused ("do not contact me this way")
     *   null       — never recorded
     * Reads the single non-revoked record (setConsent keeps exactly one active).
     */
    public function consentDecision(string $type): ?string
    {
        return $this->consentRecords()
            ->where('consent_type', $type)
            ->whereNull('revoked_at')
            ->value('decision');
    }

    /**
     * Every consent type with its current decision + meta — the payload the
     * agent and client UIs render from.
     */
    public function consentStates(): array
    {
        $active = $this->consentRecords()
            ->whereNull('revoked_at')
            ->get()
            ->keyBy('consent_type');

        $states = [];
        foreach (self::CONSENT_TYPES as $type => $label) {
            $rec = $active->get($type);
            $states[] = [
                'type'        => $type,
                'label'       => $label,
                'group'       => str_starts_with($type, 'channel_') ? 'channel'
                                  : ($type === 'marketing_communications' ? 'marketing' : 'compliance'),
                'decision'    => $rec?->decision,
                'recorded_at' => $rec?->given_at,
            ];
        }

        return $states;
    }

    /**
     * Record a tri-state consent decision (given|declined) for a type.
     * Supersedes any prior active record of the same type so there is exactly
     * one active record per type, preserving the full history as the audit
     * chain. The ContactConsentRecord observer recomputes channel opt-out flags
     * on the create. Spec: .ai/specs/contact-consent.md §4.
     */
    public function setConsent(
        string $type,
        string $decision = ContactConsentRecord::DECISION_GIVEN,
        string $method = 'electronic',
        ?int $userId = null,
        string $source = 'agent_web',
        ?int $documentId = null,
    ): ContactConsentRecord {
        $this->supersedeActiveConsent($type, $userId);

        return ContactConsentRecord::create([
            'contact_id'           => $this->id,
            'agency_id'            => $this->agency_id,
            'consent_type'         => $type,
            'decision'             => $decision,
            'given_at'             => now(),
            'given_by_user_id'     => $userId,
            'method'               => $method,
            'source'               => $source,
            'evidence_document_id' => $documentId,
        ]);
    }

    /** Return a consent type to the "not recorded" state. */
    public function clearConsent(string $type, ?int $userId = null, ?string $reason = null): void
    {
        $this->revokeConsent($type, $userId, $reason ?? 'Cleared');
        $this->recomputeChannelConsent();
    }

    /**
     * Retained for existing callers (e.g. MarketingConsentService::optInContact).
     * Records an affirmative ("given") decision via the unified setConsent path.
     */
    public function recordConsent(string $type, string $method, int $userId, ?int $documentId = null): ContactConsentRecord
    {
        return $this->setConsent($type, ContactConsentRecord::DECISION_GIVEN, $method, $userId, 'system', $documentId);
    }

    /** Stamp the current active record of a type as superseded (no new row). */
    private function supersedeActiveConsent(string $type, ?int $userId): void
    {
        $this->consentRecords()
            ->where('consent_type', $type)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at'         => now(),
                'revoked_by_user_id' => $userId,
                'revoked_reason'     => 'Superseded by new decision',
            ]);
    }

    public function revokeConsent(string $type, ?int $userId = null, ?string $reason = null): void
    {
        $this->consentRecords()
            ->where('consent_type', $type)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $userId,
                'revoked_reason' => $reason,
            ]);
    }

    public function accessLog(): HasMany
    {
        return $this->hasMany(ContactAccessLog::class)->latest('accessed_at');
    }

    // ── Channel opt-out (M3.6) ──

    /**
     * Check if this contact can be contacted via a given channel.
     * Returns false if opted out (consent revoked or never given).
     */
    public function canSendVia(string $channel): bool
    {
        $channelAllowed = match ($channel) {
            'email' => !$this->opt_out_email,
            'sms' => !$this->opt_out_sms,
            'whatsapp' => !$this->opt_out_whatsapp,
            'call' => !$this->opt_out_call,
            default => true,
        };
        if (!$channelAllowed) {
            return false;
        }

        // AT-50 — an identifier-level marketing suppression hard-blocks EVERY
        // channel only for a contact that stopped ALL messages. A marketing-only
        // opt-out leaves transactional channels open: marketing is still gated by
        // messaging_opt_out_at / isContactSuppressed in the outreach sender, but
        // transactional comms (a live sale) are not silenced here.
        if ($this->messaging_all_blocked) {
            return !app(\App\Services\SellerOutreach\MarketingConsentService::class)->isContactSuppressed($this);
        }

        return true;
    }

    /**
     * Recompute denormalised opt-out flags from consent records.
     * Opted out = no active consent for that channel type.
     */
    public function recomputeChannelConsent(): void
    {
        $channelMap = [
            'channel_email' => 'opt_out_email',
            'channel_sms' => 'opt_out_sms',
            'channel_whatsapp' => 'opt_out_whatsapp',
            'channel_call' => 'opt_out_call',
        ];

        $updates = ['last_consent_check_at' => now()];
        foreach ($channelMap as $consentType => $column) {
            // Opted out unless the latest active record explicitly GRANTS the
            // channel. A 'declined' decision or no record at all = opted out.
            $decision = $this->consentDecision($consentType);
            $updates[$column] = $decision !== ContactConsentRecord::DECISION_GIVEN;
        }

        $this->updateQuietly($updates);
    }

    // ── Messaging opt-in (AT-45) ──

    /**
     * Record an explicit marketing opt-in — e.g. the seller replied YES to a
     * consent-request message. A recorded FACT for compliance + re-engagement.
     *
     * It does NOT lift an existing opt-out: the send gate still honours
     * messaging_opt_out_at. Mirrors the opt-out triplet that
     * RecordOptOutOnContact sets on the contact.
     */
    public function recordOptIn(?string $reason, int $userId): void
    {
        $this->update([
            'messaging_opted_in_at'                => now(),
            'messaging_opt_in_reason'              => $reason,
            'messaging_opt_in_recorded_by_user_id' => $userId,
        ]);
    }

    /** True when an explicit messaging opt-in has been recorded. */
    public function isOptedIn(): bool
    {
        return $this->messaging_opted_in_at !== null;
    }

    // ── AT-50 — derived 3-state communication status ─────────────────────

    public const COMM_OPTED_IN            = 'opted_in';
    public const COMM_MARKETING_OPTED_OUT = 'marketing_opted_out';
    public const COMM_ALL_BLOCKED         = 'all_blocked';
    public const COMM_TRANSACTION_ONLY    = 'transaction_only';

    // ── AT-81 — outreach-consent sub-state (the 5-state doctrine) ─────────
    //
    // The master opt-in/opt-out axis (above) is preserved; these add the
    // source/reason dimension. messaging_opt_out_kind carries the opted-out
    // sub-state; outreach_permission_asked_at carries the not-yet-opted-out
    // PENDING marker. The 5 states below are DERIVED (never stored) by
    // outreachConsentState().

    /** messaging_opt_out_kind value: explicit decline — never re-contact. */
    public const OPT_OUT_KIND_DECLINED    = 'declined';
    /** messaging_opt_out_kind value: silence-lapse — re-contactable in future. */
    public const OPT_OUT_KIND_NO_RESPONSE = 'no_response';

    /** Never contacted — approachable for a first consent-request. NOT held consent. */
    public const OUTREACH_INITIAL     = 'initial';
    /** Consent-request sent, awaiting reply — the no-response clock is running. */
    public const OUTREACH_PENDING     = 'pending';
    /** Responded yes — actual marketing consent obtained. */
    public const OUTREACH_CONFIRMED   = 'confirmed';
    /** Window elapsed in silence — opted out, but distinct from a decline. */
    public const OUTREACH_NO_RESPONSE = 'no_response';
    /** Responded no — explicit opt-out. */
    public const OUTREACH_DECLINED    = 'declined';

    /**
     * The contact's communication status, DERIVED (never stored):
     *   opted_in            — not opted out (default; receives all).
     *   transaction_only    — opted out BUT in a live sale, so business comms
     *                         about that sale continue (the transaction lock
     *                         outranks a stop-all, which is server-side blocked
     *                         while a sale is live).
     *   all_blocked         — opted out, NO live sale, AND messaging_all_blocked:
     *                         every channel stopped ("All messages stopped").
     *   marketing_opted_out — opted out, NO live sale, marketing-only: marketing
     *                         silenced but transactional channels remain open.
     *
     * The live-transaction check only runs when the contact IS opted out, so the
     * common (opted-in) case costs no query.
     */
    public function communicationStatus(): string
    {
        if ($this->messaging_opt_out_at === null) {
            return self::COMM_OPTED_IN;
        }

        $agencyId = (int) $this->agency_id;
        if ($agencyId > 0
            && app(\App\Services\SellerOutreach\TransactionStateService::class)
                ->isInLiveTransaction($agencyId, $this)) {
            return self::COMM_TRANSACTION_ONLY;
        }

        if ($this->messaging_all_blocked) {
            return self::COMM_ALL_BLOCKED;
        }

        return self::COMM_MARKETING_OPTED_OUT;
    }

    /**
     * AT-81 — the outreach-consent state in the 5-state doctrine, DERIVED from
     * the master opt-out/opt-in columns plus the sub-state dimension. Distinct
     * from communicationStatus() (which is the master gating axis); this is the
     * richer marketing-relationship state used for honest labelling + the future
     * re-contact pool.
     *
     *   INITIAL    — never contacted, no consent held (approachable).
     *   PENDING    — consent-request sent, awaiting reply (clock running).
     *   CONFIRMED  — replied yes (consent obtained).
     *   NO_RESPONSE— window elapsed silent (opted out, re-contactable later).
     *   DECLINED   — replied no (opted out, never re-contact).
     */
    public function outreachConsentState(): string
    {
        if ($this->messaging_opt_out_at !== null) {
            // Legacy opt-outs carry no kind → they were all explicit declines.
            return $this->messaging_opt_out_kind === self::OPT_OUT_KIND_NO_RESPONSE
                ? self::OUTREACH_NO_RESPONSE
                : self::OUTREACH_DECLINED;
        }
        if ($this->messaging_opted_in_at !== null) {
            return self::OUTREACH_CONFIRMED;
        }
        if ($this->outreach_permission_asked_at !== null) {
            return self::OUTREACH_PENDING;
        }
        return self::OUTREACH_INITIAL;
    }

    /** AT-81 — true while a consent-request is awaiting a reply (re-send blocked). */
    public function isOutreachPending(): bool
    {
        return $this->messaging_opt_out_at === null
            && $this->messaging_opted_in_at === null
            && $this->outreach_permission_asked_at !== null;
    }

    // ── AT-91 — WhatsApp Outreach Summary board (single source of truth) ──
    //
    // The four outreach-OUTCOME states the board columns and the contacts-list
    // drill-through both count by. Derived purely from the consent timestamps +
    // kind (NO transaction_only / all_blocked master-gating carve-out — this is
    // the outreach outcome, mirroring outreachConsentState()). 'awaiting' is the
    // documented leftover: a WhatsApp-sent contact derived back to INITIAL
    // (e.g. clicked the link, clearing the PENDING marker, but not yet opted
    // in/out; or a legacy pre-AT-81 send that never stamped the marker). It is
    // NOT a primary column but is counted + drillable so no send is ever lost.
    // See .ai/specs/whatsapp-outreach-summary.md §3.1.

    /** The four primary board states, in display order. */
    public const OUTREACH_BOARD_STATES = ['pending', 'confirmed', 'opt_out_no_response', 'opted_out'];

    /** Every drillable board state (the four primary + the 'awaiting' leftover). */
    public const OUTREACH_BOARD_STATES_ALL = ['pending', 'confirmed', 'opt_out_no_response', 'opted_out', 'awaiting'];

    /**
     * The raw SQL boolean fragment for one board state. STATIC SQL — no user
     * input is interpolated (the column names and literals are fixed), so it is
     * safe for whereRaw / a CASE WHEN. The single source consumed by BOTH the
     * board's SUM(CASE…) read model AND the contacts-list ?outreach_state filter
     * so the cell count and its drilled list can never drift apart.
     *
     * @param string $state One of self::OUTREACH_BOARD_STATES_ALL.
     * @param string $alias Table/alias the contacts columns live under ('' for none).
     */
    public static function outreachStateSql(string $state, string $alias = 'contacts'): string
    {
        $p = $alias === '' ? '' : $alias . '.';

        return match ($state) {
            'pending' => "{$p}messaging_opt_out_at IS NULL AND {$p}messaging_opted_in_at IS NULL AND {$p}outreach_permission_asked_at IS NOT NULL",
            'confirmed' => "{$p}messaging_opt_out_at IS NULL AND {$p}messaging_opted_in_at IS NOT NULL",
            'opt_out_no_response' => "{$p}messaging_opt_out_at IS NOT NULL AND {$p}messaging_opt_out_kind = 'no_response'",
            'opted_out' => "{$p}messaging_opt_out_at IS NOT NULL AND ({$p}messaging_opt_out_kind <> 'no_response' OR {$p}messaging_opt_out_kind IS NULL)",
            'awaiting' => "{$p}messaging_opt_out_at IS NULL AND {$p}messaging_opted_in_at IS NULL AND {$p}outreach_permission_asked_at IS NULL",
            default => throw new \InvalidArgumentException("Unknown outreach board state: {$state}"),
        };
    }

    /** Filter to contacts in a given board state (drill-through). */
    public function scopeOutreachState(Builder $query, string $state): Builder
    {
        return $query->whereRaw('(' . self::outreachStateSql($state, $query->getModel()->getTable()) . ')');
    }

    /**
     * Filter to contacts with at least one (non-deleted) WhatsApp outreach send
     * in the same agency — the board population. Correlated EXISTS using the
     * outreach_send_contact_idx (agency_id, contact_id, sent_at) index.
     */
    public function scopeHasWhatsappOutreach(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereExists(function ($sub) use ($table) {
            $sub->selectRaw('1')
                ->from('seller_outreach_sends as sos')
                ->whereColumn('sos.contact_id', "{$table}.id")
                ->whereColumn('sos.agency_id', "{$table}.agency_id")
                ->where('sos.channel', 'whatsapp')
                ->whereNull('sos.deleted_at');
        });
    }

    /**
     * AT-81 — start the no-response clock when a consent-request is sent. Only
     * from INITIAL: a confirmed opt-in needs no clock (consent already held), and
     * an opted-out contact is gate-blocked from sending anyway. Idempotent — never
     * moves an existing pending marker (so the window measures from the FIRST ask).
     */
    public function markOutreachPending($at = null): void
    {
        if ($this->messaging_opt_out_at !== null
            || $this->messaging_opted_in_at !== null
            || $this->outreach_permission_asked_at !== null) {
            return;
        }
        $this->forceFill(['outreach_permission_asked_at' => $at ?? now()])->save();
    }

    /**
     * AT-81 — clear the PENDING marker the moment the contact engages (opt-in,
     * opt-out, click, or a future inbound reply) so the timeout never lapses
     * someone who is mid-reply. Idempotent; no-op when not pending.
     */
    public function clearOutreachPending(): void
    {
        if ($this->outreach_permission_asked_at !== null) {
            $this->forceFill(['outreach_permission_asked_at' => null])->save();
        }
    }

    /**
     * Badge metadata for the derived status — plain-English label, a CoreX
     * design-system badge class, and a state-specific tooltip. THE single source
     * for both the contact header pill and the Outreach-tab status badge.
     *
     * AT-81 — surfaces all FIVE outreach-consent states distinctly (the doctrine):
     * the opted-IN master case fans out into INITIAL / PENDING / CONFIRMED, and
     * the opted-OUT marketing case into NO_RESPONSE (silence) vs DECLINED
     * (explicit) — so no surface ever mislabels a lapse as a refusal, or a
     * sent-but-unanswered pitch as plain opted-in. Master gating
     * (communicationStatus) is unchanged; this is the richer display layer.
     *
     * @return array{key:string, label:string, class:string, title:string}
     */
    public function communicationStatusMeta(): array
    {
        return match ($this->communicationStatus()) {
            self::COMM_TRANSACTION_ONLY => [
                'key'   => self::COMM_TRANSACTION_ONLY,
                'label' => 'Transaction-only',
                'class' => 'ds-badge-warning',
                'title' => 'Marketing is off, but messages about an active sale continue until it concludes.',
            ],
            self::COMM_ALL_BLOCKED => [
                'key'   => self::COMM_ALL_BLOCKED,
                'label' => 'All messages stopped',
                'class' => 'ds-badge-danger',
                'title' => 'The contact asked to stop all messages.',
            ],
            self::COMM_MARKETING_OPTED_OUT => $this->messaging_opt_out_kind === self::OPT_OUT_KIND_NO_RESPONSE
                ? [
                    'key'   => self::OUTREACH_NO_RESPONSE,
                    'label' => 'No response — lapsed',
                    'class' => 'ds-badge-warning',
                    'title' => 'No reply to the consent request within the window — suppressed, but not an explicit opt-out.',
                ]
                : [
                    'key'   => self::COMM_MARKETING_OPTED_OUT,
                    'label' => 'Marketing opted out',
                    'class' => 'ds-badge-orange',
                    'title' => 'The contact opted out of marketing messages.',
                ],
            // Opted-in master state — fan out the three opted-in sub-states so
            // INITIAL, PENDING and CONFIRMED are each visibly distinct (AT-81).
            default => $this->messaging_opted_in_at !== null
                ? [
                    'key'   => self::OUTREACH_CONFIRMED,
                    'label' => 'Opted in · confirmed',
                    'class' => 'ds-badge-success',
                    'title' => 'The contact confirmed they want to hear from you.',
                ]
                : ($this->outreach_permission_asked_at !== null
                    ? [
                        'key'   => self::OUTREACH_PENDING,
                        'label' => 'Awaiting reply',
                        'class' => 'ds-badge-orange',
                        'title' => 'Consent request sent — awaiting their reply.',
                    ]
                    : [
                        'key'   => self::OUTREACH_INITIAL,
                        'label' => 'Opted in · not contacted',
                        'class' => 'ds-badge-success',
                        'title' => 'Opted in by default — no outreach has been sent yet.',
                    ]),
        };
    }

    /** The user who recorded the messaging opt-in (for "by whom" display). */
    public function optInRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'messaging_opt_in_recorded_by_user_id');
    }

    /**
     * The user who recorded the messaging opt-out (for "by whom" display).
     * NULL when the opt-out was self-service (the recipient tapped the per-send
     * link) — see messaging_opt_out_source / [[at49-self-service-optout]].
     */
    public function optOutRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'messaging_opt_out_recorded_by_user_id');
    }

    // ── Buyer CRM (M4) ──

    public function buyerActivityLog(): HasMany
    {
        return $this->hasMany(BuyerActivityLog::class)->latest('activity_date');
    }

    public function buyerStateTransitions(): HasMany
    {
        return $this->hasMany(BuyerStateTransition::class)->latest('occurred_at');
    }

    public function buyerPropertyViews(): HasMany
    {
        return $this->hasMany(BuyerPropertyView::class);
    }

    public function scopeBuyers($query)
    {
        return $query->where('is_buyer', true);
    }

    public function recordManualActivity(string $type, int $userId, ?string $notes = null): void
    {
        app(\App\Services\BuyerStateService::class)->markActivity(
            $this, $type, null, null, null, $userId, $notes ? ['notes' => $notes] : null
        );
    }

    // ── Calendar event links (M2.2) ──

    public function calendarEventLinks(): MorphMany
    {
        return $this->morphMany(CalendarEventLink::class, 'linkable');
    }

    public function calendarEvents()
    {
        return $this->morphToMany(CalendarEvent::class, 'linkable', 'calendar_event_links', null, 'calendar_event_id');
    }

    // ── Communication archive (AT-59) ──

    /**
     * Communications linked to this contact through communication_links (the
     * Intelligence layer). Soft-deleted links are excluded; soft-deleted /
     * pruned communications are excluded by the Communication model's own
     * SoftDeletes scope. Eager-load this on the show page to avoid N+1.
     */
    public function communications()
    {
        return $this->morphToMany(
            \App\Models\Communications\Communication::class,
            'linkable',
            'communication_links',
            null,
            'communication_id'
        )->withPivot(['link_method', 'confirmed_at'])
         ->wherePivotNull('deleted_at');
    }

    /**
     * Count of OUTBOUND communications for a channel — the authoritative source
     * for the contact comms tiles (AT-59). Provisional and confirmed rows both
     * count: reconciliation PROMOTES a provisional row in place, so a click and
     * its eventual real send are always exactly one row. Purged rows excluded.
     *
     * Contact-details Phase 4 — a row flagged send_status=not_delivered is
     * EXCLUDED. A failed send is kept on record (the audit trail), but it must
     * never inflate the "N messages sent" tile — that's the whole point of the
     * flag existing.
     *
     * Uses the eager-loaded relation when present (no extra query), otherwise a
     * single scoped count.
     */
    public function outboundCommCount(string $channel): int
    {
        if ($this->relationLoaded('communications')) {
            return $this->communications
                ->where('channel', $channel)
                ->where('direction', \App\Models\Communications\Communication::DIRECTION_OUTBOUND)
                ->whereNull('purged_at')
                ->where('send_status', \App\Models\Communications\Communication::SEND_STATUS_SENT)
                ->count();
        }

        return $this->communications()
            ->where('channel', $channel)
            ->where('direction', \App\Models\Communications\Communication::DIRECTION_OUTBOUND)
            ->whereNull('communications.purged_at')
            ->where('communications.send_status', \App\Models\Communications\Communication::SEND_STATUS_SENT)
            ->count();
    }

    /**
     * Move last_contacted_at FORWARD to the given time (defaults to now). Never
     * moves it backwards, so an out-of-order ingested message cannot rewind the
     * "last contacted" marker. Used by every comm create/ingest path (AT-59).
     */
    public function touchLastContacted($at = null): void
    {
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();

        if (! $this->last_contacted_at || $at->gt($this->last_contacted_at)) {
            $this->forceFill(['last_contacted_at' => $at])->save();
        }
    }

    /**
     * AT-372 — mark the contact as CONTACTED by an explicit agent action (Mark as
     * Now / Pick Date / Mark contacted + note). This is a first-class signal, stored
     * separately from the comms so it is never wiped by a send's recompute. Then
     * re-derive last_contacted_at (which is the max of this and the sent comms).
     */
    public function markContacted($at = null): void
    {
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();
        $this->forceFill(['contacted_marked_at' => $at])->save();
        $this->recomputeLastContacted();
    }

    /**
     * Contact-details Phase 4 + AT-372 — re-derive last_contacted_at as the LATER of
     * the two truthful "contacted" signals:
     *   (a) the latest still-counting outbound SENT comm (send_status=sent), and
     *   (b) the explicit agent "contacted" mark (contacted_marked_at, AT-372).
     * Taking the max means neither signal wipes the other: a not-sent send (which is
     * send_status != sent, AT-323) never contributes, an explicit mark survives the
     * next send's recompute, and flagging a send not_delivered correctly rewinds the
     * comms half without touching the explicit mark.
     *
     * Called whenever a communication's send_status changes (flag not_delivered,
     * mark sent, resend) and by markContacted(). touchLastContacted()'s forward-only
     * bump is still used at raw log time; recompute is the authority that reconciles.
     */
    public function recomputeLastContacted(): void
    {
        $max = $this->communications()
            ->where('direction', \App\Models\Communications\Communication::DIRECTION_OUTBOUND)
            ->where('communications.send_status', \App\Models\Communications\Communication::SEND_STATUS_SENT)
            ->whereNull('communications.purged_at')
            ->max('communications.occurred_at');

        $sent = $max ? \Illuminate\Support\Carbon::parse($max) : null;
        $explicit = $this->contacted_marked_at; // AT-372 — cast to Carbon

        // last_contacted_at = the later of the two signals (null only if both null).
        $new = null;
        foreach ([$sent, $explicit] as $candidate) {
            if ($candidate !== null && ($new === null || $candidate->gt($new))) {
                $new = $candidate;
            }
        }

        $unchanged = ($this->last_contacted_at === null && $new === null)
            || ($this->last_contacted_at !== null && $new !== null && $this->last_contacted_at->eq($new));

        if (! $unchanged) {
            $this->forceFill(['last_contacted_at' => $new])->save();
        }
    }
}
