<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §5.5. The bid log — the auction
 * register for CPA purposes (§18). Never updated in place except to
 * retract (retracted_at/by/reason); never deleted through the UI.
 */
class AuctionBid extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const CHANNELS = ['in_room', 'online', 'phone', 'absentee', 'proxy'];

    protected $fillable = [
        'agency_id', 'auction_id', 'auction_lot_id', 'auction_bidder_id', 'amount', 'channel',
        'placed_at', 'is_proxy', 'proxy_max', 'recorded_by_id', 'is_winning',
        'retracted_at', 'retracted_by_id', 'retracted_reason', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'placed_at' => 'datetime',
        'is_proxy' => 'boolean',
        'proxy_max' => 'decimal:2',
        'is_winning' => 'boolean',
        'retracted_at' => 'datetime',
    ];

    public function auctionLot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class);
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(AuctionBidder::class, 'auction_bidder_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function isRetracted(): bool
    {
        return $this->retracted_at !== null;
    }
}
