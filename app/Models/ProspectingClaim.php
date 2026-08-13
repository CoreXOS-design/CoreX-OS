<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class ProspectingClaim extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'prospecting_listing_id',
        'property_id',
        'user_id',
        'status',
        'notes',
        'claimed_at',
        'pitched_at',
        'feedback_at',
        'last_updated_at',
        'released_at',
        'release_reason',
        'flagged_at',
        'warned_at',
        'is_active',
    ];

    protected $casts = [
        'claimed_at'      => 'datetime',
        'pitched_at'      => 'datetime',
        'feedback_at'     => 'datetime',
        'last_updated_at' => 'datetime',
        'released_at'     => 'datetime',
        'flagged_at'      => 'datetime',
        'warned_at'       => 'datetime',
        'is_active'       => 'boolean',
    ];

    /**
     * MIC funnel phase 2 — days this claim has sat unworked. The staleness clock is
     * last_updated_at (bumped every time the claim is worked via recordActionOnClaim),
     * falling back to claimed_at for a never-touched claim.
     */
    public function staleAgeDays(): int
    {
        $since = $this->last_updated_at ?? $this->claimed_at ?? $this->created_at;
        return $since ? (int) $since->diffInDays(now()) : 0;
    }

    /** Warn-worthy: active, unworked ≥ warn_days, and not already warned since last worked. */
    public function needsStaleWarning(int $warnDays): bool
    {
        return $this->is_active
            && $this->released_at === null
            && $this->warned_at === null
            && $this->staleAgeDays() >= $warnDays;
    }

    /** Stale for BM/admin move-or-keep review: active, unworked ≥ release_days. */
    public function isStaleForReview(int $releaseDays): bool
    {
        return $this->is_active
            && $this->released_at === null
            && $this->staleAgeDays() >= $releaseDays;
    }

    /**
     * Canonical claim-status vocabulary — the SINGLE SOURCE OF TRUTH.
     *
     * Every consumer of prospecting_claims.status (the feedback() validator,
     * ProspectingListingStateEnricher, SuggestedActionResolver, FlagStaleClaimsJob,
     * SmartFilterPresetService, ClaimFeedbackRecorded, ClaimFeedbackTemplates and the
     * model methods below) keys off THIS list. Do not introduce a status string that
     * is not declared here. (Reconciled 2026-06-26: the old ClaimFeedbackTemplates
     * vocabulary — interested / pitched / scheduled — diverged from this set and would
     * 422 against the feedback() validator; it has been mapped onto these constants.)
     */
    public const STATUS_CLAIMED        = 'claimed';         // initial — set at claim time only
    public const STATUS_CONTACTED      = 'contacted';       // agent reached / attempted the owner
    public const STATUS_MEETING_SET    = 'meeting_set';     // appointment booked
    public const STATUS_LISTING        = 'listing';         // owner agreed — securing the mandate
    public const STATUS_NOT_INTERESTED = 'not_interested';  // owner declined — closes the claim
    public const STATUS_LOST           = 'lost';            // dead end (wrong contact / already listed) — closes the claim

    /** All valid statuses (initial + feedback outcomes). */
    public const STATUSES = [
        self::STATUS_CLAIMED,
        self::STATUS_CONTACTED,
        self::STATUS_MEETING_SET,
        self::STATUS_LISTING,
        self::STATUS_NOT_INTERESTED,
        self::STATUS_LOST,
    ];

    /** Statuses an agent may set via the feedback endpoint (excludes the initial 'claimed'). */
    public const FEEDBACK_STATUSES = [
        self::STATUS_CONTACTED,
        self::STATUS_MEETING_SET,
        self::STATUS_LISTING,
        self::STATUS_NOT_INTERESTED,
        self::STATUS_LOST,
    ];

    /** Outcomes that auto-release (deactivate) the claim. */
    public const CLOSING_STATUSES = [
        self::STATUS_NOT_INTERESTED,
        self::STATUS_LOST,
    ];

    /** Plain-English labels for visible chips/badges (STANDARDS F.8). */
    public const STATUS_LABELS = [
        self::STATUS_CLAIMED        => 'Claimed',
        self::STATUS_CONTACTED      => 'Contacted',
        self::STATUS_MEETING_SET    => 'Meeting set',
        self::STATUS_LISTING        => 'Listing secured',
        self::STATUS_NOT_INTERESTED => 'Not interested',
        self::STATUS_LOST           => 'Lost',
    ];

    /**
     * Human-readable label for a claim status — used by the "Prospected" badge and any
     * other visible surface. Falls back to a humanised form for unknown values so a
     * stray status never renders a raw enum token to a user.
     */
    public static function humanStatus(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'Worked';
        }
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public function listing()
    {
        return $this->belongsTo(ProspectingListing::class, 'prospecting_listing_id');
    }

    /**
     * The agency property this claim locks (Pitch Now #4). Rotating portal refs
     * mean the claim must key on the property so it can't be re-pitched under a
     * fresh ref. Nullable — a claim for a listing with no matched property yet.
     */
    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function hasReceivedFeedback(): bool
    {
        return $this->feedback_at !== null;
    }

    /**
     * True for a claim that has fallen out of its 48h no-feedback window and is
     * eligible to be released back to the pool / silently re-claimed.
     *
     * A PITCHED claim (pitched_at set — the agent captured + linked a contact
     * via "Pitch now") is PERMANENT: it never expires and is never re-claimable
     * on the timer. The 48h window applies ONLY to a claim-without-pitch.
     */
    public function isExpired(): bool
    {
        if ($this->pitched_at !== null) {
            return false;
        }

        return $this->is_active
            && !$this->feedback_at
            && $this->claimed_at < now()->subHours(48);
    }

    /** A pitched claim is locked permanently to its agent (no 48h expiry). */
    public function isPitched(): bool
    {
        return $this->pitched_at !== null;
    }

    public function needsReminder(): bool
    {
        return $this->is_active
            && $this->feedback_at
            && in_array($this->status, [self::STATUS_CONTACTED, self::STATUS_MEETING_SET], true)
            && $this->last_updated_at < now()->subDays(7);
    }

    public function needsBmFlag(): bool
    {
        return $this->is_active
            && $this->status === self::STATUS_LISTING
            && $this->feedback_at
            && $this->last_updated_at < now()->subDays(14)
            && !$this->flagged_at;
    }
}
