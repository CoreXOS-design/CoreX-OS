<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §17.6.2 — append-only history of the owner's
 * work terms on a property (the no-approval limit and the variation tolerance):
 * every change with old/new value (null new value = "inherit the agency
 * default"), who changed it, when, and how/when it was agreed with the owner.
 */
class RentalPropertyWorkTermChange extends Model
{
    use BelongsToAgency;

    public const FIELD_NO_APPROVAL_LIMIT = 'no_approval_limit';
    public const FIELD_VARIATION_TOLERANCE = 'variation_tolerance';

    protected $fillable = [
        'agency_id',
        'property_id',
        'field',
        'old_value',
        'new_value',
        'changed_by_user_id',
        'changed_at',
        'agreed_with',
        'note',
    ];

    protected $casts = [
        'old_value' => 'decimal:2',
        'new_value' => 'decimal:2',
        'changed_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
