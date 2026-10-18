<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.2 - append-only change log of a property's (or an owner's) "Who looks at faults first" list: the list
 * before and after in plain English, who changed it, when, and how/when it was agreed. Never edited, never deleted.
 */
class RentalFirstResponderChange extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'property_id', 'contact_id', 'changed_by_user_id', 'changed_at', 'old_summary', 'new_summary', 'agreed_with'];

    protected $casts = ['changed_at' => 'datetime'];

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
