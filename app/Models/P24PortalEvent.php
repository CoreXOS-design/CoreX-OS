<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class P24PortalEvent extends Model
{
    use BelongsToAgency;

    public $timestamps = false;

    protected $table = 'p24_portal_events';

    protected $fillable = [
        'portal_id',
        'agency_id',
        'actor_type',
        'actor_label',
        'event',
        'target_row_id',
        'target_external_id',
        'meta_json',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'meta_json'  => 'array',
        'created_at' => 'datetime',
    ];

    public function portal(): BelongsTo
    {
        return $this->belongsTo(P24OnboardingPortal::class, 'portal_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(P24ImportRow::class, 'target_row_id');
    }

    public static function log(array $attrs): self
    {
        $attrs['created_at'] = $attrs['created_at'] ?? now();
        return static::create($attrs);
    }

    /** Mask IP for display (e.g. 102.33.x.x). */
    public static function maskIp(?string $ip): string
    {
        if (!$ip) return 'unknown';
        $parts = explode('.', $ip);
        if (count($parts) === 4) return $parts[0] . '.' . $parts[1] . '.x.x';
        return substr($ip, 0, 8) . '…';
    }
}
