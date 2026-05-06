<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserBankingDetail extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'user_id',
        'agency_id',
        'account_holder',
        'bank_name',
        'branch_code',
        'account_number',
        'account_type',
        'is_primary',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'verified_at' => 'datetime',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ── Accessors ──

    public function getMaskedAccountNumberAttribute(): string
    {
        $num = $this->attributes['account_number'] ?? '';
        if (strlen($num) <= 4) {
            return $num;
        }

        return str_repeat('*', strlen($num) - 4) . substr($num, -4);
    }

    /**
     * Mask account_number in array/JSON output to prevent accidental leakage.
     */
    public function toArray()
    {
        $array = parent::toArray();

        if (isset($array['account_number'])) {
            $array['account_number'] = $this->masked_account_number;
        }

        return $array;
    }
}
