<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-takeon-import.md §2.2. No agency scope of its own —
 * always reached through its parent run, which is agency-scoped. Mirrors
 * App\Models\P24ImportRow deliberately.
 */
class RentalTakeOnImportRow extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_INCLUDED = 'included';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_ERROR = 'error';

    public const ACTION_CREATE = 'create';
    public const ACTION_MATCH = 'match';

    public const COMPLETENESS_COMPLETE = 'complete';
    public const COMPLETENESS_DRAFT = 'draft';

    protected $fillable = [
        'run_id',
        'row_number',
        'payload_json',
        'property_match_action',
        'property_match_tracked_id',
        'property_match_label',
        'landlord_match_json',
        'tenant_match_json',
        'lease_completeness',
        'errors_json',
        'warnings_json',
        'status',
        'target_property_id',
        'target_lease_id',
        'target_landlord_contact_ids_json',
        'target_tenant_contact_ids_json',
        'confirmed_at',
        'confirmed_by',
        'archived_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'landlord_match_json' => 'array',
        'tenant_match_json' => 'array',
        'errors_json' => 'array',
        'warnings_json' => 'array',
        'target_landlord_contact_ids_json' => 'array',
        'target_tenant_contact_ids_json' => 'array',
        'confirmed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RentalTakeOnImportRun::class, 'run_id');
    }

    public function targetProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'target_property_id');
    }

    public function targetLease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'target_lease_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function hasBlockingErrors(): bool
    {
        return !empty($this->errors_json);
    }
}
