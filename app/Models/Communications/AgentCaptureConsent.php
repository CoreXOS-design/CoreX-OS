<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-136 — an agent's capture decision for ONE matched contact (does the agent's
 * WhatsApp with this contact get archived, body and all). SEPARATE from the
 * AT-125 contact marketing opt-out. BelongsToAgency + SoftDeletes (no hard deletes).
 */
class AgentCaptureConsent extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'agent_capture_consent';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_OPTED_IN  = 'opted_in';
    public const STATUS_OPTED_OUT = 'opted_out';

    protected $fillable = [
        'agency_id', 'agent_user_id', 'contact_id', 'status', 'reason',
        'decided_at', 'decided_by_user_id',
        'admin_flagged', 'admin_flag_note', 'admin_flag_by_user_id', 'admin_flagged_at',
    ];

    protected $casts = [
        'decided_at'       => 'datetime',
        'admin_flagged'    => 'boolean',
        'admin_flagged_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function scopePending($q)   { return $q->where('status', self::STATUS_PENDING); }
    public function scopeOptedIn($q)   { return $q->where('status', self::STATUS_OPTED_IN); }
    public function scopeOptedOut($q)  { return $q->where('status', self::STATUS_OPTED_OUT); }

    public function scopeForAgent($q, int $agentUserId) { return $q->where('agent_user_id', $agentUserId); }

    public function isOptedIn(): bool { return $this->status === self::STATUS_OPTED_IN; }
}
