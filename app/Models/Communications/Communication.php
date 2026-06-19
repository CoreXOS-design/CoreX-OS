<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Communication Archive index row (AT-32). Channel-agnostic; raw payload on
 * disk, index here. Append-only + soft-delete; 5-yr prune is a soft event.
 */
class Communication extends Model
{
    use SoftDeletes, BelongsToAgency;

    const CHANNEL_EMAIL    = 'email';
    const CHANNEL_WHATSAPP = 'whatsapp';
    const DIRECTION_INBOUND  = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'agency_id', 'channel', 'direction', 'external_id', 'thread_key',
        'from_identifier', 'participant_identifiers', 'occurred_at', 'captured_at',
        'provisional_at', 'subject', 'body_text', 'body_preview', 'raw_path',
        'has_attachments', 'content_hash', 'text_hash', 'source_ref',
        'purged_at', 'purged_reason',
    ];

    protected $casts = [
        'participant_identifiers' => 'array',
        'occurred_at'            => 'datetime',
        'captured_at'            => 'datetime',
        'provisional_at'         => 'datetime',
        'purged_at'              => 'datetime',
        'has_attachments'        => 'boolean',
    ];

    // ── Relationships ──

    public function attachments(): HasMany
    {
        return $this->hasMany(CommunicationAttachment::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(CommunicationLink::class);
    }

    // ── Scopes ──

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeEmail($query)
    {
        return $query->where('channel', self::CHANNEL_EMAIL);
    }

    public function scopeWhatsapp($query)
    {
        return $query->where('channel', self::CHANNEL_WHATSAPP);
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeNotPurged($query)
    {
        return $query->whereNull('purged_at');
    }

    /** Provisional rows: created on click, not yet reconciled to a real send. */
    public function scopeProvisional($query)
    {
        return $query->whereNotNull('provisional_at');
    }

    /** Confirmed rows: ingested or reconciled (provisional_at cleared). */
    public function scopeConfirmed($query)
    {
        return $query->whereNull('provisional_at');
    }

    public function isProvisional(): bool
    {
        return $this->provisional_at !== null;
    }

    /** Records past the 5-year retention window (by occurred_at), not yet purged. */
    public function scopePastRetention($query, ?\DateTimeInterface $cutoff = null)
    {
        $cutoff ??= now()->subYears(5);
        return $query->whereNull('purged_at')->where('occurred_at', '<', $cutoff);
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }
}
