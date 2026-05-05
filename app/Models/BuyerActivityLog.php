<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'buyer_activity_log';

    protected $fillable = [
        'contact_id', 'agency_id', 'activity_type', 'activity_date',
        'related_event_id', 'related_property_id', 'related_feedback_id',
        'metadata', 'logged_by_user_id',
    ];

    protected $casts = [
        'activity_date' => 'datetime',
        'metadata' => 'array',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function property(): BelongsTo { return $this->belongsTo(Property::class, 'related_property_id'); }
}
