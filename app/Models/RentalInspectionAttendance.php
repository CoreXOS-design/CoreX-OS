<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — one fact: who attended an inspection, in what
 * capacity, and whether they did.
 *
 * Evidence, so APPEND-ONLY (same discipline as RentalInspectionSignature / the item findings): a
 * correction is a new row that supersedes the old one (`superseded_at` + `superseded_by_id`); a record
 * withdrawn without a replacement has `superseded_at` set and no `superseded_by_id`. Nothing is edited
 * in place and nothing is hard-deleted. The inspection's own archive carries these rows with it.
 *
 * Recorded as FACTS with who recorded them and when — never a conclusion about what attendance means
 * for anyone's rights (§45.2 principle 1).
 */
class RentalInspectionAttendance extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const PARTY_TENANT = 'tenant';
    public const PARTY_LANDLORD = 'landlord';
    public const PARTY_AGENT = 'agent';
    public const PARTY_OTHER = 'other';

    public const OUTCOME_ATTENDED = 'attended';
    public const OUTCOME_DID_NOT_ATTEND = 'did_not_attend';

    public const AS_SELF = 'self';
    public const AS_REPRESENTATIVE = 'representative';
    public const AS_CO_OCCUPANT = 'co_occupant';
    public const AS_OTHER = 'other';

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'party_role',
        'party_contact_id',
        'party_user_id',
        'attendee_name',
        'attended_as',
        'represents_party_role',
        'outcome',
        'arrived_at',
        'note',
        'recorded_by_user_id',
        'recorded_at',
        'client_idempotency_key',
        'superseded_at',
        'superseded_by_id',
        'created_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'superseded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function partyContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'party_contact_id');
    }

    public function partyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** The row that replaced this one, if any. A withdrawn row has none. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** Rows that still count — never superseded and never withdrawn. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function isLive(): bool
    {
        return $this->superseded_at === null;
    }

    public function didAttend(): bool
    {
        return $this->outcome === self::OUTCOME_ATTENDED;
    }

    /** The legal combinations — read by the service's validation and by the controller's `in:` rules. */
    public static function attendedAsKeys(): array
    {
        return [self::AS_SELF, self::AS_REPRESENTATIVE, self::AS_CO_OCCUPANT, self::AS_OTHER];
    }
}
