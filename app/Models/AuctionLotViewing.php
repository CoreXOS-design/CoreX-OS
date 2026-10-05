<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §5.6. A scheduled viewing window
 * before the sale. Feeds the `auction_viewing` calendar event class
 * (AuctionCalendarSource) and the public lot page's viewing times.
 */
class AuctionLotViewing extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'auction_lot_id', 'agent_id', 'starts_at', 'ends_at', 'is_by_appointment', 'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_by_appointment' => 'boolean',
    ];

    public function auctionLot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
