<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only event log for the Other Agency Stock "request edit access"
 * flow. NEVER updated, NEVER deleted — see OtherAgencyStockContentLock and
 * .ai/specs/other-agency-stock.md §8a for the full state machine (current
 * state = latest row for the property; see the creating migration's
 * docblock for the exact derivation).
 */
class OtherAgencyStockUnlock extends Model
{
    use BelongsToAgency;

    public const EVENT_REQUESTED = 'requested';
    public const EVENT_APPROVED  = 'approved';
    public const EVENT_DECLINED  = 'declined';
    public const EVENT_RELOCKED  = 'relocked';

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id', 'property_id', 'event_type',
        'requested_by_user_id', 'reason',
        'request_id', 'decided_by_user_id',
        'relocked_by_user_id',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function relockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relocked_by_user_id');
    }

    /** The 'requested' row this decision resolves (only set on approved/declined rows). */
    public function request(): BelongsTo
    {
        return $this->belongsTo(self::class, 'request_id');
    }

    /**
     * Current unlock state for a property, derived from its latest event row.
     *
     * @return array{state: string, row: ?self} state is 'locked'|'pending'|'unlocked'
     */
    public static function currentStateFor(Property $property): array
    {
        $latest = self::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->latest('id')
            ->first();

        if (! $latest) {
            return ['state' => 'locked', 'row' => null];
        }

        return match ($latest->event_type) {
            self::EVENT_APPROVED  => ['state' => 'unlocked', 'row' => $latest],
            self::EVENT_REQUESTED => ['state' => 'pending', 'row' => $latest],
            default               => ['state' => 'locked', 'row' => $latest],
        };
    }

    /** @throws \LogicException always — this table is append-only. */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('OtherAgencyStockUnlock rows are append-only and can never be updated.');
    }

    /** @throws \LogicException always — this table is append-only. */
    public function delete()
    {
        throw new \LogicException('OtherAgencyStockUnlock rows are append-only and can never be deleted.');
    }
}
