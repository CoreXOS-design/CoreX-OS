<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PPRA Inspection Pack Phase D — item (i), .ai/specs/ppra-inspection-pack.md
 * §4.3/§6.5 (v3). Versioned: editing creates a new row and soft-deletes the
 * prior current one — never mutate a row in place. Either entry_type
 * independently satisfies item (i).
 */
class AgencyTransformationNote extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'agency_transformation_notes';

    protected $fillable = [
        'agency_id',
        'entry_type',
        'structured_data',
        'document_path',
        'document_original_name',
        'summary',
        'created_by_user_id',
    ];

    protected $casts = [
        'structured_data' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The current (latest non-deleted) version for an agency, if any. */
    public static function currentFor(int $agencyId): ?self
    {
        return static::where('agency_id', $agencyId)
            ->latest('created_at')
            ->first();
    }
}
