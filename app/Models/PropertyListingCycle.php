<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One listing cycle of a property (status history by cycle) — .ai/specs/sold-stock.md §3. */
class PropertyListingCycle extends Model
{
    use BelongsToAgency;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at'                 => 'datetime',
        'ended_at'                   => 'datetime',
        'previous_owner_contact_ids' => 'array',
        'previous_buyer_contact_ids' => 'array',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }
}
