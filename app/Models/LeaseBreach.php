<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-notices.md - a breach notice issued against the tenant (outstanding payment, or another breach of the lease) or the
 * landlord. One row per breach: issued -> remedied, or escalated to a notice to vacate (tenant breaches only, once the remedy window has
 * passed unremedied). Soft delete only. Exactly as visible as its lease (Lease::visibleTo).
 */
class LeaseBreach extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    public const KIND_PAYMENT = 'payment';
    public const KIND_OTHER = 'other';

    public const STATUS_ISSUED = 'issued';
    public const STATUS_REMEDIED = 'remedied';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUSES = [self::STATUS_ISSUED, self::STATUS_REMEDIED, self::STATUS_ESCALATED];
    public const STATUS_LABELS = [
        self::STATUS_ISSUED => 'Issued',
        self::STATUS_REMEDIED => 'Remedied',
        self::STATUS_ESCALATED => 'Escalated to vacate',
    ];

    protected $fillable = [
        'agency_id', 'branch_id', 'lease_id', 'kind', 'breach_party', 'amount', 'clause', 'reason', 'status', 'issued_on', 'remedy_deadline',
        'remedy_business_days', 'issued_by_user_id', 'rental_notice_id', 'batch_ref', 'remedied_at', 'remedied_by_user_id', 'remedied_note',
        'vacate_issued_at', 'vacate_issued_by_user_id', 'vacate_rental_notice_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_on' => 'date',
        'remedy_deadline' => 'date',
        'remedied_at' => 'datetime',
        'vacate_issued_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function remediedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remedied_by_user_id');
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(RentalNotice::class, 'rental_notice_id')->withTrashed();
    }

    public function vacateNotice(): BelongsTo
    {
        return $this->belongsTo(RentalNotice::class, 'vacate_rental_notice_id')->withTrashed();
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_PAYMENT ? 'Payment' : 'Other';
    }

    /** "The tenant" / "The landlord" - who is in breach. */
    public function partyLabel(): string
    {
        return $this->breach_party === Lease::NOTICE_BY_LANDLORD ? 'The landlord' : 'The tenant';
    }

    /** The party who has to be told (the one NOT in breach). */
    public function otherParty(): string
    {
        return $this->breach_party === Lease::NOTICE_BY_LANDLORD ? Lease::NOTICE_BY_TENANT : Lease::NOTICE_BY_LANDLORD;
    }

    /** The remedy window has passed (the deadline is before today). */
    public function remedyWindowPassed(): bool
    {
        return $this->remedy_deadline !== null && $this->remedy_deadline->lt(\Carbon\Carbon::today());
    }

    /** A notice to vacate may be issued: a tenant breach, still unremedied, once the remedy window has passed. */
    public function canIssueVacate(): bool
    {
        return $this->isIssued() && $this->breach_party === Lease::NOTICE_BY_TENANT && $this->remedyWindowPassed();
    }

    /** Branch Split: a child sits in its parent's branch, never the acting user's (branch-isolation-spec §7a). */
    protected function branchParent(): array
    {
        return [\App\Models\Lease::class, 'lease_id'];
    }
}
