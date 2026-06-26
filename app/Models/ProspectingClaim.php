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
        'user_id',
        'status',
        'notes',
        'claimed_at',
        'feedback_at',
        'last_updated_at',
        'released_at',
        'flagged_at',
        'is_active',
    ];

    protected $casts = [
        'claimed_at'      => 'datetime',
        'feedback_at'     => 'datetime',
        'last_updated_at' => 'datetime',
        'released_at'     => 'datetime',
        'flagged_at'      => 'datetime',
        'is_active'       => 'boolean',
    ];

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

    public function isExpired(): bool
    {
        return $this->is_active
            && !$this->feedback_at
            && $this->claimed_at < now()->subHours(48);
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
