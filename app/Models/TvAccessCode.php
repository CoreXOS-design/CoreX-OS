<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TvAccessCode extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'agency_id',
        'code',
        'created_by',
        'expires_at',
        'last_used_at',
        'is_active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // ── Relationships ──

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForCompany($query)
    {
        return $query->whereNull('branch_id');
    }

    // ── Helpers ──

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Generate a unique 6-digit numeric code.
     *
     * Uniqueness is checked GLOBALLY (queryWithoutAgencyScope), not just
     * within the caller's own agency: the public /tv/verify lookup matches
     * on `code` alone with no agency context (there is no authenticated
     * user yet), so if two agencies were ever allowed to hold the same
     * active code simultaneously, verify() would resolve to whichever row
     * MySQL returns first — silently serving the wrong agency's dashboard.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::queryWithoutAgencyScope()->where('code', $code)->where('is_active', true)->exists());

        return $code;
    }
}
