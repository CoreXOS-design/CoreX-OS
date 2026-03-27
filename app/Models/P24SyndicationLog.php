<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P24SyndicationLog extends Model
{
    public $timestamps = false;

    protected $table = 'p24_syndication_logs';

    protected $fillable = [
        'property_id',
        'action',
        'request_payload',
        'response_payload',
        'status_code',
        'created_at',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'created_at'       => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
