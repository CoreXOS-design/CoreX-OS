<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 4 — public-share link for a frozen PresentationVersion snapshot.
 *
 * Find one by token + check revoked_at IS NULL + expires_at > now()
 * before serving the public page. PublicPresentationController does that
 * gating; this model is just a data carrier.
 */
final class PresentationSnapshotLink extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'presentation_id',
        'presentation_version_id',
        'agency_id',
        'token',
        'mode',
        'recipient_contact_id',
        'recipient_label',
        'created_by_user_id',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
        'first_viewed_at',
        'last_viewed_at',
        'view_count',
        'first_fingerprint',
        'flagged_at',
        'flagged_reason',
        'last_flag_notified_at',
        'refresh_requested_at',
        'refresh_requested_by_name',
        'refresh_requested_message',
    ];

    protected $casts = [
        'expires_at'              => 'datetime',
        'revoked_at'              => 'datetime',
        'first_viewed_at'         => 'datetime',
        'last_viewed_at'          => 'datetime',
        'view_count'              => 'integer',
        'flagged_at'              => 'datetime',
        'last_flag_notified_at'   => 'datetime',
        'refresh_requested_at'    => 'datetime',
    ];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    public function presentationVersion(): BelongsTo
    {
        return $this->belongsTo(PresentationVersion::class);
    }

    public function recipientContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recipient_contact_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(PresentationSnapshotView::class, 'snapshot_link_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    /**
     * Mask the token for logs / UI display: keep first 6 chars, redact the rest.
     */
    public function maskedToken(): string
    {
        return mb_substr($this->token, 0, 6) . '…';
    }
}
