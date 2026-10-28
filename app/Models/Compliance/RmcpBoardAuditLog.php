<?php

namespace App\Models\Compliance;

use App\Models\Concerns\StampsOnBehalfOf;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/** Append-only trail of the board approval: sent, resent, viewed, signed, completed, cancelled, approved. */
class RmcpBoardAuditLog extends Model
{
    use BelongsToAgency, StampsOnBehalfOf;

    public $timestamps = false;

    protected $table = 'rmcp_board_audit_log';

    protected $fillable = [
        'agency_id', 'rmcp_version_id', 'letter_id', 'signature_id', 'event_type',
        'actor_user_id', 'actor_label', 'ip_address', 'user_agent', 'meta',
    ];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];
}
