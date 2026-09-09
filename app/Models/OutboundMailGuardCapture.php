<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AT-URGENT-2026-09-09 — a message the outbound mail guard intercepted. The
 * durable, countable, listable record of "what got held" — the source of
 * truth Johan asked for ("142 messages were held while this was on, here
 * they are"), independent of whether a local Mailpit-style sink exists
 * (it never does on live).
 *
 * View/count/export only — deliberately no release mechanism. See
 * .ai/releases/... for the reasoning (a raw-MIME replay would bypass
 * whatever state machine the original message type normally updates).
 */
class OutboundMailGuardCapture extends Model
{
    protected $fillable = [
        'to_addresses',
        'cc_addresses',
        'bcc_addresses',
        'subject',
        'raw_mime',
        'environment',
        'forwarded_to_sink',
        'captured_at',
    ];

    protected $casts = [
        'forwarded_to_sink' => 'boolean',
        'captured_at' => 'datetime',
    ];
}
