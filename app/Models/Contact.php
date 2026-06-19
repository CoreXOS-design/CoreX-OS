<?php

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Scopes\ContactScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Contact extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

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
        'birthday', 'birthday_reminder', 'id_number', 'id_number_captured_at', 'id_number_source', 'address',
        // AT-60 — structured PROPERTY-address capture (independent of the
        // residential `address` above; never auto-composed into it).
        'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
        'street_number', 'street_name', 'suburb', 'city', 'province',
        'p24_province_id', 'p24_city_id', 'p24_suburb_id',
        'loaded_at', 'modified_at', 'last_contacted_at',
        'whatsapp_count', 'email_count',
        'bank_name', 'bank_account_name', 'bank_account_number',
        'bank_branch_name', 'bank_branch_code', 'bank_account_type',
        'opt_out_email', 'opt_out_sms', 'opt_out_whatsapp', 'opt_out_call',
        'last_consent_check_at',
        'is_buyer', 'buyer_state', 'last_activity_at',
        'buyer_pipeline_entered_at', 'buyer_pipeline_notes',
        'preapproval_amount', 'preapproval_expires_at', 'preapproval_institution',
        'messaging_opt_out_at', 'messaging_opt_out_reason', 'messaging_opt_out_recorded_by_user_id', 'messaging_opt_out_source',
        'messaging_all_blocked',
        'messaging_opted_in_at', 'messaging_opt_in_reason', 'messaging_opt_in_recorded_by_user_id',
    ];

    protected $casts = [
        'birthday'              => 'date',
        'birthday_reminder'     => 'boolean',
        'id_number_captured_at' => 'datetime',
        'loaded_at'             => 'datetime',
        'modified_at'       => 'datetime',
        'last_contacted_at' => 'datetime',
        'is_buyer'          => 'boolean',
        'last_activity_at'  => 'datetime',
        'buyer_pipeline_entered_at' => 'datetime',
        'preapproval_amount'        => 'decimal:2',
        'preapproval_expires_at'    => 'date',
        'messaging_opt_out_at'      => 'datetime',
        'messaging_all_blocked'     => 'boolean',
        'messaging_opted_in_at'     => 'datetime',
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

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'contact_property')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
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
     * Badge metadata for the derived status — plain-English label + a CoreX
     * design-system badge class. Safe for list and detail views.
     *
     * @return array{key:string, label:string, class:string}
     */
    public function communicationStatusMeta(): array
    {
        return match ($this->communicationStatus()) {
            self::COMM_TRANSACTION_ONLY => [
                'key'   => self::COMM_TRANSACTION_ONLY,
                'label' => 'Transaction-only',
                'class' => 'ds-badge-warning',
            ],
            self::COMM_ALL_BLOCKED => [
                'key'   => self::COMM_ALL_BLOCKED,
                'label' => 'All messages stopped',
                'class' => 'ds-badge-danger',
            ],
            self::COMM_MARKETING_OPTED_OUT => [
                'key'   => self::COMM_MARKETING_OPTED_OUT,
                'label' => 'Marketing opted out',
                'class' => 'ds-badge-warning',
            ],
            default => [
                'key'   => self::COMM_OPTED_IN,
                'label' => 'Opted in',
                'class' => 'ds-badge-success',
            ],
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
                ->count();
        }

        return $this->communications()
            ->where('channel', $channel)
            ->where('direction', \App\Models\Communications\Communication::DIRECTION_OUTBOUND)
            ->whereNull('communications.purged_at')
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
}
