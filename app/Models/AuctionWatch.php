<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A lot a buyer is watching (shown in My account). */
class AuctionWatch extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'auction_watchlist';

    protected $fillable = ['agency_id', 'lot_id', 'client_user_id'];

    public function lot()
    {
        return $this->belongsTo(AuctionLot::class, 'lot_id')->withTrashed();
    }
}
