<?php

namespace App\Models\DealV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-158 DR2 · WS4 (§4.6, §16 POPIA) — the immutable secure-link access log.
 *
 * Append-only. Every secure-link interaction (link opened, OTP sent/verified/
 * failed, downloaded, revoked) writes exactly one row and it is NEVER mutated
 * or deleted — update() and delete() throw. This is the POPIA evidence that
 * identity was verified before a personal-data document streamed. Mirrors the
 * comms / e-sign audit doctrine.
 *
 * Not multi-tenant-scoped on read (reads are always via a distribution the
 * caller already owns); agency_id is stamped for provenance/filtering only.
 */
class DealDocumentAccessLog extends Model
{
    protected $table = 'deal_document_access_log';

    public $timestamps = false; // only created_at (DB default), no updated_at

    protected $fillable = [
        'agency_id',
        'distribution_id',
        'event',
        'ip',
        'user_agent',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public const EVENT_LINK_CLICKED = 'link_clicked';
    public const EVENT_OTP_SENT = 'otp_sent';
    public const EVENT_OTP_VERIFIED = 'otp_verified';
    public const EVENT_OTP_FAILED = 'otp_failed';
    public const EVENT_DOWNLOADED = 'downloaded';
    public const EVENT_REVOKED = 'revoked';

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(DealDocumentDistribution::class, 'distribution_id');
    }

    /**
     * The ONLY way to write a row. Append-only by construction.
     */
    public static function record(DealDocumentDistribution $distribution, string $event, array $meta = [], ?string $ip = null, ?string $userAgent = null): self
    {
        return static::create([
            'agency_id' => $distribution->agency_id,
            'distribution_id' => $distribution->id,
            'event' => $event,
            'ip' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    // ── Immutability guards (mirror the e-sign / comms audit pattern) ──

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('deal_document_access_log is append-only and cannot be updated.');
        });
        static::deleting(function () {
            throw new \LogicException('deal_document_access_log is append-only and cannot be deleted.');
        });
    }
}
