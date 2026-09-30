<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.2 — agency default
 * (property_id null) or per-property override. "Don't build one way where
 * there are options; build the options and let the agency set it up; it
 * can vary PER PROPERTY." — Johan.
 */
class RentalFaultRoutingProfile extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const ROUTE_CARETAKER = 'caretaker';
    public const ROUTE_SUPPLIER = 'supplier';
    public const ROUTE_OWNER_FIRST = 'owner_first';
    public const ROUTE_AGENT_REVIEW = 'agent_review';

    protected $fillable = [
        'agency_id',
        'property_id',
        'emergency_route',
        'emergency_caretaker_spend_limit',
        'emergency_supplier_id',
        'emergency_supplier_spend_limit',
        'emergency_owner_first_spend_limit',
        'non_emergency_route',
        'non_emergency_caretaker_spend_limit',
        'non_emergency_supplier_id',
        'non_emergency_supplier_spend_limit',
        'created_by_user_id',
    ];

    protected $casts = [
        'emergency_caretaker_spend_limit' => 'decimal:2',
        'emergency_supplier_spend_limit' => 'decimal:2',
        'emergency_owner_first_spend_limit' => 'decimal:2',
        'non_emergency_caretaker_spend_limit' => 'decimal:2',
        'non_emergency_supplier_spend_limit' => 'decimal:2',
    ];

    /**
     * §13.2's own "seeded default profile, never broken" note — today's
     * existing built behaviour exactly: every fault, emergency or not,
     * reaches an agent/owner via the normal flow. Same firstOrCreate-keyed
     * pattern as AgencyServiceType::seedDefaultsFor() (AT-229).
     */
    public static function seedDefaultFor(int $agencyId): void
    {
        static::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId, 'property_id' => null],
            [
                'emergency_route' => self::ROUTE_OWNER_FIRST,
                'non_emergency_route' => self::ROUTE_AGENT_REVIEW,
            ],
        );
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function emergencySupplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'emergency_supplier_id');
    }

    public function nonEmergencySupplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'non_emergency_supplier_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(RentalFaultRoutingRule::class)->where('is_active', true)->orderBy('sort_order');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
