<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of one property's "How I want faults handled" (section 22.1): who, when, what it was, what it became, on what evidence. */
class RentalPropertyFaultSettingChange extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'date', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withoutGlobalScopes();
    }
}
