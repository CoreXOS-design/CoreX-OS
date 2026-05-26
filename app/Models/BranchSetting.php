<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class BranchSetting extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'key',
        'value',
    ];

    /**
     * Get setting for branch, fallback optional default.
     */
    public static function getForBranch(int $branchId, string $key, $default = null)
    {
        return static::where('branch_id', $branchId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    /**
     * Set/update setting for branch.
     */
    public static function setForBranch(int $branchId, string $key, $value): void
    {
        static::updateOrCreate(
            [
                'branch_id' => $branchId,
                'key' => $key,
            ],
            [
                'value' => (string)$value,
            ]
        );
    }
}
