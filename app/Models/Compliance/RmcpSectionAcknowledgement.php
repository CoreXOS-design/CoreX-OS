<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RmcpSectionAcknowledgement extends Model
{
    protected $fillable = [
        'rmcp_acknowledgement_id',
        'rmcp_section_id',
        'acknowledged',
        'acknowledged_at',
        'acknowledgement_response',
        'ip_address',
    ];

    protected $casts = [
        'acknowledged'    => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function acknowledgement(): BelongsTo
    {
        return $this->belongsTo(RmcpAcknowledgement::class, 'rmcp_acknowledgement_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(RmcpSection::class, 'rmcp_section_id');
    }
}
