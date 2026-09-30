<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.2 — "plumbing emergencies go
 * to supplier X, electrical to supplier Y." Most-specific-wins resolution
 * in RentalFaultRoutingService::resolve() (§13.3).
 */
class RentalFaultRoutingRule extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_fault_routing_profile_id',
        'category',
        'urgency',
        'route',
        'agency_service_provider_id',
        'spend_limit',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'spend_limit' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RentalFaultRoutingProfile::class, 'rental_fault_routing_profile_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'agency_service_provider_id');
    }
}
