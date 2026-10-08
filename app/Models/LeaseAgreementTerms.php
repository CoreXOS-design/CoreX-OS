<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/leases.md §15.7.2 / §15.10 M1 — what a lease agreement says beyond rent and dates, one
 * row per lease TERM. The structured, queryable copy: the capture screen pre-fills from it and a
 * later renewal carries it forward. The printed, sealed document stays the legal record.
 */
class LeaseAgreementTerms extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'lease_agreement_terms';

    public const SOURCE_CAPTURED = 'captured';
    public const SOURCE_CARRIED_FORWARD = 'carried_forward';
    public const SOURCE_ESIGN_HARVEST = 'esign_harvest';
    public const SOURCE_CONFIRMED = 'confirmed';

    protected $fillable = [
        'agency_id',
        'lease_id',
        'adults',
        'max_other_persons',
        'pets',
        'escalation_percent',
        'no_escalation',
        'escalation_month',
        'earliest_termination_date',
        'renewal_option_months',
        'electricity_arrangement',
        'other_conditions',
        'notice_period',
        'notice_period_unit',
        'earliest_notice_date',
        'early_cancellation_allowed',
        'early_cancellation_notice',
        'early_cancellation_notice_unit',
        'early_cancellation_penalty',
        'notice_terms_source',
        'notice_terms_confirmed_at',
        'notice_terms_confirmed_by',
        'extra',
        'source',
    ];

    protected $casts = [
        'notice_terms_confirmed_at' => 'datetime',
        'adults' => 'integer',
        'max_other_persons' => 'integer',
        'escalation_percent' => 'decimal:2',
        'no_escalation' => 'boolean',
        'escalation_month' => 'integer',
        'earliest_termination_date' => 'date',
        'renewal_option_months' => 'integer',
        'notice_period' => 'integer',
        'earliest_notice_date' => 'date',
        'early_cancellation_notice' => 'integer',
        'extra' => 'array',
    ];

    /** Withheld from the lease's own relation on purpose — see Lease::agreementTerms(). */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    /**
     * The terms row for a lease — the existing one, a soft-deleted one brought back, or a new one.
     * `lease_id` is UNIQUE, so a row that was soft-deleted can never simply be re-inserted; it has to
     * be restored (BUILD_STANDARD §5 "soft-deleted-then-recreated"). Not saved here — the caller
     * fills it and saves once.
     */
    public static function forLease(Lease $lease): self
    {
        $terms = static::withTrashed()->where('lease_id', $lease->id)->first();

        if ($terms) {
            if ($terms->trashed()) {
                $terms->restore();
            }

            return $terms;
        }

        $terms = new static(['lease_id' => $lease->id, 'source' => self::SOURCE_CAPTURED]);
        $terms->agency_id = $lease->agency_id;

        return $terms;
    }
}
