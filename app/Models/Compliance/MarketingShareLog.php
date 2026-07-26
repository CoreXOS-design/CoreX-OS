<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\StampsOnBehalfOf;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingShareLog extends Model
{
    use BelongsToAgency, StampsOnBehalfOf;

    protected $table = 'marketing_share_log';

    public $timestamps = false;

    protected $fillable = [
        'property_id', 'user_id', 'agency_id',
        'channel', 'recipient_context', 'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
