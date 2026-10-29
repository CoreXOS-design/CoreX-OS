<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A FICA document a buyer has on file with ONE agency, so they can tick "use existing" for their next auction there.
 * It is deliberately per agency: one agency never receives another agency's copy of somebody's ID.
 */
class BidderDocument extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'client_user_id', 'kind', 'original_name', 'path', 'mime_type', 'size', 'expires_at'];

    protected $casts = ['expires_at' => 'date', 'size' => 'integer'];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
