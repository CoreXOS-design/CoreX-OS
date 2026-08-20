<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record per "Copy Link" click on a buyer's wishlist/portal share link
 * (Buyers Report, Johan 2026-08-20). See the creating migration's docblock
 * for why this is event-shaped rather than a single timestamp column.
 */
class WishlistShareEvent extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'contact_id', 'contact_match_id',
        'channel', 'shared_by_user_id', 'shared_at',
    ];

    protected $casts = [
        'shared_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(ContactMatch::class, 'contact_match_id');
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }
}
