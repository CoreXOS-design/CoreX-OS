<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class P24OnboardingPortal extends Model
{
    use SoftDeletes;

    protected $table = 'p24_onboarding_portals';

    protected $fillable = [
        'agency_id',
        'token',
        'label',
        'created_by',
        'expires_at',
        'revoked_at',
        'revoked_reason',
        'last_opened_at',
        'open_count',
        'completed_at',
        'run_ids_json',
    ];

    protected $casts = [
        'run_ids_json'   => 'array',
        'expires_at'     => 'datetime',
        'revoked_at'     => 'datetime',
        'last_opened_at' => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public static function generateToken(): string
    {
        do {
            $t = Str::random(40);
        } while (static::where('token', $t)->exists());
        return $t;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(P24PortalEvent::class, 'portal_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at) return false;
        if ($this->completed_at) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function statusLabel(): string
    {
        if ($this->revoked_at) return 'Revoked';
        if ($this->completed_at) return 'Completed';
        if ($this->expires_at && $this->expires_at->isPast()) return 'Expired';
        return 'Active';
    }

    public function publicUrl(): string
    {
        return url('/onboarding/' . $this->token);
    }

    /**
     * Query p24_import_rows scoped to this portal's agency (and optional runs).
     */
    public function rowsQuery()
    {
        $q = P24ImportRow::query()
            ->where('row_type', 'listing')
            ->whereHas('run', fn($r) => $r->where('agency_id', $this->agency_id));

        if (!empty($this->run_ids_json)) {
            $q->whereIn('run_id', $this->run_ids_json);
        }

        return $q;
    }
}
