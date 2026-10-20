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
        'vacate_issued_at', 'vacate_issued_by_user_id', 'vacate_rental_notice_id', 'remedy_day_basis', 'vacate_date', 'vacate_time',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_on' => 'date',
        'remedy_deadline' => 'date',
        'vacate_date' => 'date',
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

    /** "7 calendar days" / "20 business days" - the remedy window this breach was issued under. */
    public function remedyWindowLabel(): string
    {
        return (int) $this->remedy_business_days . ' ' . ($this->remedy_day_basis === 'calendar' ? 'calendar' : 'business') . ' days';
    }

    /** "12h00" - the vacate time as the notice prints it. */
    public static function vacateTimeText(?string $hhmm): string
    {
        return $hhmm ? str_replace(':', 'h', substr($hhmm, 0, 5)) : '';
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

    /** Today's date (Y-m-d) in the agency's own timezone - the one place "today" is read for the remedy / vacate gates. */
    protected function agencyTodayDate(): string
    {
        $tz = $this->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'UTC');

        return \Carbon\Carbon::now($tz)->toDateString();
    }

    /** The remedy-by date has been reached (today or earlier) - "was it fixed?" is now the question (rental-notices.md §7b). */
    public function remedyDateReached(): bool
    {
        return $this->remedy_deadline !== null && $this->remedy_deadline->toDateString() <= $this->agencyTodayDate();
    }

    /**
     * The whole of the remedy-by day has gone by (it is now a later day than the remedy-by date, in the agency's timezone): only then may a
     * notice to vacate go out - the tenant has the whole of their last remedy day (rental-notices.md §7b, Johan 20 Oct 2026).
     */
    public function remedyWindowPassed(): bool
    {
        return $this->remedy_deadline !== null && $this->remedy_deadline->toDateString() < $this->agencyTodayDate();
    }

    /** The first day a notice to vacate can go out: the day after the remedy-by date. Null when there is no remedy-by date. */
    public function vacateAvailableFrom(): ?\Carbon\Carbon
    {
        return $this->remedy_deadline?->copy()->startOfDay()->addDay();
    }

    /** Days from today to the remedy-by date: positive = days left, 0 = due today, negative = overdue. Null once the breach is closed or has no date. */
    public function daysToRemedy(): ?int
    {
        if (! $this->isIssued() || $this->remedy_deadline === null) {
            return null;
        }

        return (int) \Carbon\Carbon::today()->diffInDays($this->remedy_deadline->copy()->startOfDay(), false);
    }

    /** "7 days left" / "1 day left" / "due today" / "1 day overdue" / "3 days overdue"; null when there is nothing to count down. */
    public function daysLeftText(): ?string
    {
        $d = $this->daysToRemedy();
        if ($d === null) {
            return null;
        }
        if ($d === 0) {
            return 'due today';
        }
        $n = abs($d);

        return $n . ' ' . ($n === 1 ? 'day' : 'days') . ($d > 0 ? ' left' : ' overdue');
    }

    /** The remedy-by date has passed and no remedy is recorded: the breach is ready for a notice to vacate (shown red). */
    public function isOverdue(): bool
    {
        return ($this->daysToRemedy() ?? 0) < 0;
    }

    /** A notice to vacate may be issued: a tenant breach, still unremedied, from the day AFTER the remedy-by date. */
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
