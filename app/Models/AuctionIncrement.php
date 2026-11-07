<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One rung of an auction's bid ladder (.ai/specs/auctions.md §4.2). */
class AuctionIncrement extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'auction_id', 'from_amount', 'increment'];

    protected $casts = ['from_amount' => 'decimal:2', 'increment' => 'decimal:2'];

    public function auction()
    {
        return $this->belongsTo(Auction::class);
    }
}
