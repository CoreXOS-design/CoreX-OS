<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\DealV2\AgencyServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.2 - one entry of "Who looks at faults first": a supplier, its place in the order (1-3), which faults
 * it is for (all, or only the listed fault types) and what it is told (inspect and report back, or information only). Belongs to a rental PROPERTY or,
 * as the owner's default, to a CONTACT (the owner as landlord) - exactly one of the two. Soft-deleted when taken off the list.
 */
class RentalFirstResponder extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const MAX_ENTRIES = 3;

    public const SCOPE_ALL = 'all';
    public const SCOPE_TYPES = 'types';

    public const TELL_INSPECT = 'inspect';
    public const TELL_INFO_ONLY = 'info_only';

    public const TELL_LABELS = [
        self::TELL_INSPECT => 'Notify to inspect and report back',
        self::TELL_INFO_ONLY => 'Notify for information only',
    ];

    protected $fillable = [
        'agency_id', 'property_id', 'contact_id', 'agency_service_provider_id', 'position', 'fault_scope', 'fault_type_ids', 'tell_mode', 'created_by_user_id',
    ];

    protected $casts = [
        'fault_type_ids' => 'array',
        'position' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'agency_service_provider_id')->withoutGlobalScopes()->withTrashed();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** Does this entry cover a fault of this type? ($faultTypeId null = a fault with no type: only an "all faults" entry covers it.) */
    public function covers(?int $faultTypeId): bool
    {
        if ($this->fault_scope !== self::SCOPE_TYPES) {
            return true;
        }

        return $faultTypeId !== null && in_array($faultTypeId, array_map('intval', (array) $this->fault_type_ids), true);
    }

    public static function tellLabel(?string $mode): string
    {
        return self::TELL_LABELS[$mode] ?? self::TELL_LABELS[self::TELL_INSPECT];
    }
}
