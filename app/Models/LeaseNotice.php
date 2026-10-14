<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/lease-notices.md - a notice given by the tenant or the landlord: received -> the agent reviews it -> accepted /
 * rejected -> (accepted) sent to the other party. One row per notice given. Soft delete only. It is exactly as visible as its
 * lease (Lease::visibleTo) - never listed or opened on its own authority.
 */
class LeaseNotice extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUSES = [self::STATUS_RECEIVED, self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    public const STATUS_LABELS = [
        self::STATUS_RECEIVED => 'Needs review',
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_WITHDRAWN => 'Withdrawn',
    ];

    public const VIA_PORTAL = 'portal';
    public const VIA_AGENT = 'agent';

    public const DECISION_AS_GIVEN = 'as_given';
    public const DECISION_CORRECTED = 'corrected';
    public const DECISION_REJECTED = 'rejected';

    protected $fillable = [
        'agency_id', 'branch_id', 'lease_id', 'given_by', 'via', 'given_by_contact_id', 'recorded_by_user_id',
        'notice_date', 'intended_end_date', 'reason',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'status', 'accepted_end_date', 'decision_kind', 'decision_note', 'decided_by_user_id', 'decided_at',
        'property_outcome', 'show_available_from_on_portals', 'flags', 'other_party_told_at',
    ];

    protected $casts = [
        'notice_date' => 'date',
        'intended_end_date' => 'date',
        'accepted_end_date' => 'date',
        'decided_at' => 'datetime',
        'other_party_told_at' => 'datetime',
        'show_available_from_on_portals' => 'boolean',
        'flags' => 'array',
    ];

    /** Deleted-related-record rule (BUILD_STANDARD §4): a notice about a since-archived lease still names it. */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function givenByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'given_by_contact_id')->withoutGlobalScopes()->withTrashed();
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    /** "The tenant" / "The landlord". */
    public function givenByLabel(): string
    {
        return $this->given_by === Lease::NOTICE_BY_LANDLORD ? 'The landlord' : 'The tenant';
    }

    /** The party who has to be told once the notice is accepted. */
    public function otherParty(): string
    {
        return $this->given_by === Lease::NOTICE_BY_LANDLORD ? Lease::NOTICE_BY_TENANT : Lease::NOTICE_BY_LANDLORD;
    }

    /** The end date in force: what the agent accepted, else what was given. */
    public function effectiveEndDate(): \Carbon\CarbonInterface
    {
        return $this->accepted_end_date ?? $this->intended_end_date;
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null && $this->attachment_path !== '';
    }

    /** Branch Split: a child sits in its parent's branch, never the acting user's (branch-isolation-spec §7a). */
    protected function branchParent(): array
    {
        return [\App\Models\Lease::class, 'lease_id'];
    }
}
