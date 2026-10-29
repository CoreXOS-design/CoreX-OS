<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One bid. Append-only: a wrong bid is retracted (status), never deleted (.ai/specs/auctions.md §4.7). */
class AuctionBid extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_VALID = 'valid';
    public const STATUS_RETRACTED = 'retracted';
    public const SOURCES = ['online' => 'Online', 'auto_bid' => 'Auto-bid', 'floor' => 'Floor'];

    protected $fillable = [
        'agency_id', 'auction_id', 'lot_id', 'registration_id', 'amount', 'placed_at', 'source', 'status',
        'retracted_by', 'retracted_at', 'retract_reason', 'ip',
    ];

    protected $casts = ['amount' => 'decimal:2', 'placed_at' => 'datetime', 'retracted_at' => 'datetime'];

    public function lot()
    {
        return $this->belongsTo(AuctionLot::class, 'lot_id')->withTrashed();
    }

    public function auction()
    {
        return $this->belongsTo(Auction::class)->withTrashed();
    }

    public function registration()
    {
        return $this->belongsTo(AuctionRegistration::class, 'registration_id')->withTrashed();
    }

    public function scopeValid($query)
    {
        return $query->where('status', self::STATUS_VALID);
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /** Bids on lots the user's auctions data scope can see (the lot's property decides own / branch). */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->whereIn('lot_id', AuctionLot::visibleTo($user)->withTrashed()->select('auction_lots.id'));
    }
}
