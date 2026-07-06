<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * AT-183 — one immutable audit record of a WhatsApp capture opt-out purge (POPIA evidence).
 *
 * Append-only: once written it is never updated or deleted (the boot guard enforces it), so it
 * is durable proof to compliance that a purge occurred. It records the pairing, the actor, the
 * declaration reason, and the message count — NEVER any message content.
 */
class WaCapturePurgeEvent extends Model
{
    use BelongsToAgency;

    protected $table = 'wa_capture_purge_events';

    protected $fillable = [
        'agency_id', 'agent_user_id', 'contact_id', 'actor_user_id',
        'reason', 'message_count', 'purged_at',
    ];

    protected $casts = [
        'message_count' => 'integer',
        'purged_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('wa_capture_purge_events is append-only (POPIA evidence); rows cannot be modified.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('wa_capture_purge_events is append-only (POPIA evidence); rows cannot be deleted.');
        });
    }
}
