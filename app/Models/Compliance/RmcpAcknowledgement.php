<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RmcpAcknowledgement extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_EXPIRED     = 'expired';
    const STATUS_SUPERSEDED  = 'superseded';

    protected $fillable = [
        'agency_id',
        'rmcp_version_id',
        'user_id',
        'status',
        'started_at',
        'completed_at',
        'valid_until',
        'signature_path',
        'signature_type',
        'typed_signature_name',
        'ip_address',
        'user_agent',
        'device_fingerprint',
        'declaration_text',
        'sections_acknowledged_count',
        'sections_total_count',
    ];

    protected $casts = [
        'started_at'                  => 'datetime',
        'completed_at'                => 'datetime',
        'valid_until'                 => 'date',
        'sections_acknowledged_count' => 'integer',
        'sections_total_count'        => 'integer',
    ];

    // ── Relationships ──

    public function version(): BelongsTo
    {
        return $this->belongsTo(RmcpVersion::class, 'rmcp_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sectionAcknowledgements(): HasMany
    {
        return $this->hasMany(RmcpSectionAcknowledgement::class, 'rmcp_acknowledgement_id');
    }

    // ── Scopes ──

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeValid($query)
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where('valid_until', '>', now());
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where('valid_until', '<=', now()->addDays($days))
            ->where('valid_until', '>', now());
    }

    // ── Methods ──

    public function progressPercent(): int
    {
        if ($this->sections_total_count === 0) return 0;
        return (int) round(($this->sections_acknowledged_count / $this->sections_total_count) * 100);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->valid_until
            && $this->valid_until->isFuture();
    }

    public function complete(string $signaturePath, string $signatureType, string $ip, string $userAgent, ?string $typedName = null): void
    {
        $this->update([
            'status'                => self::STATUS_COMPLETED,
            'completed_at'          => now(),
            'valid_until'           => now()->addYear(),
            'signature_path'        => $signaturePath,
            'signature_type'        => $signatureType,
            'typed_signature_name'  => $typedName,
            'ip_address'            => $ip,
            'user_agent'            => $userAgent,
        ]);
    }
}
