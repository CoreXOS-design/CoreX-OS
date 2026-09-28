<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A remediation date + note against an open PPRA Inspection Pack checklist
 * item (a..m). See .ai/specs/ppra-inspection-pack.md §4.4 — "current" note
 * for an item is the latest non-deleted, non-resolved row for that agency +
 * slug. Editing creates a new row (versioned, matching
 * AgencyTransformationNote) rather than mutating one in place.
 */
class PpraInspectionGapNote extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'ppra_inspection_gap_notes';

    protected $fillable = [
        'agency_id',
        'checklist_item_slug',
        'remediation_due_date',
        'note',
        'assigned_to_user_id',
        'resolved_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'remediation_due_date' => 'date',
        'resolved_at'          => 'datetime',
    ];

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeForItem($query, string $slug)
    {
        return $query->where('checklist_item_slug', $slug);
    }

    /** The current open note for an agency + item, if any. */
    public static function currentFor(int $agencyId, string $slug): ?self
    {
        return static::where('agency_id', $agencyId)
            ->forItem($slug)
            ->open()
            ->latest('created_at')
            ->first();
    }
}
