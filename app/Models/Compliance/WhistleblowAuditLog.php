<?php

namespace App\Models\Compliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowAuditLog extends Model
{
    const UPDATED_AT = null;

    public $timestamps = false;

    protected $table = 'whistleblow_audit_log';

    protected $fillable = [
        'complaint_id',
        'user_id',
        'action',
        'action_data',
        'created_at',
    ];

    protected $casts = [
        'action_data' => 'array',
        'created_at'  => 'datetime',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(WhistleblowComplaint::class, 'complaint_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
