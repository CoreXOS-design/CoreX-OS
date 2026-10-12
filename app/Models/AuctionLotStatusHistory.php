<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-432 — .ai/specs/auctions.md §5.8. The audit trail for auction-lot
 * status transitions. Written only by AuctionLotStatusService. No
 * SoftDeletes — this table IS the audit log; nothing here is archived.
 */
class AuctionLotStatusHistory extends Model
{
    use BelongsToAgency;

    /** Eloquent's automatic pluralization would guess *_histories; the table is singular (§5.8). */
    protected $table = 'auction_lot_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id', 'auction_lot_id', 'from_status', 'to_status', 'changed_by_id', 'reason', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function auctionLot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
