<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-takeon-import.md §2.1 — the batch. Mirrors
 * App\Models\P24ImportRun deliberately.
 */
class RentalTakeOnImportRun extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_PARSING = 'parsing';
    // Landing 2 — the upload's own headers didn't match the template
    // exactly; waiting on the admin to confirm (or load a saved) column
    // mapping before any data row is parsed.
    public const STATUS_MAPPING_PENDING = 'mapping_pending';
    public const STATUS_PENDING_CONFIRM = 'pending_confirm';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'user_id',
        'status',
        'source_filename',
        'source_file_path',
        'column_mapping_id',
        'column_mapping_json',
        'counts_json',
        'error_message',
        'confirmed_at',
        'completed_at',
    ];

    protected $casts = [
        'counts_json' => 'array',
        'column_mapping_json' => 'array',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function columnMapping(): BelongsTo
    {
        return $this->belongsTo(RentalTakeOnColumnMapping::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(RentalTakeOnImportRow::class, 'run_id');
    }

    /**
     * .ai/specs/rental-takeon-import.md §7 — own/branch/agency scoping,
     * same PermissionService convention leases.md §7 already uses.
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rentals_take_on_import');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where('rental_take_on_import_runs.branch_id', $user->effectiveBranchId());
        }

        return $query->whereIn('rental_take_on_import_runs.user_id', $user->dataIdentityIds());
    }
}
