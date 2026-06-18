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

    // ── Consent & Compliance (M3.4) ──

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

    public function recordConsent(string $type, string $method, int $userId, ?int $documentId = null): ContactConsentRecord
    {
        return ContactConsentRecord::create([
            'contact_id' => $this->id,
            'agency_id' => $this->agency_id,
            'consent_type' => $type,
            'given_at' => now(),
            'given_by_user_id' => $userId,
            'method' => $method,
            'evidence_document_id' => $documentId,
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
            $hasActive = $this->consentRecords()
                ->where('consent_type', $consentType)
                ->whereNull('revoked_at')
                ->exists();
            $updates[$column] = !$hasActive;
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
}
