<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An auction-level document: Conditions of Sale, Registration Form, … (.ai/specs/auctions.md §4.3). */
class AuctionDocument extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'auction_id', 'kind', 'name', 'path', 'visibility', 'sort_order', 'uploaded_by'];

    public const KINDS = [
        'conditions_of_sale' => 'Conditions of Sale',
        'bidder_registration_form' => 'Bidder Registration Form',
        'offer_to_purchase' => 'Offer to Purchase (auction version)',
        'commission_breakdown' => 'Commission Breakdown',
        'other' => 'Other',
    ];

    /** Conditions of Sale and the Registration Form are public by default; everything else needs a login. */
    public const PUBLIC_BY_DEFAULT = ['conditions_of_sale', 'bidder_registration_form'];

    public const VISIBILITIES = ['public' => 'Everyone', 'registered' => 'Registered bidders only'];

    public function auction()
    {
        return $this->belongsTo(Auction::class);
    }
}
