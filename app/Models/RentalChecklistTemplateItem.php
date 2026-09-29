<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-430 §3.2/§3.3 — a single line inside a checklist template section.
 * `is_derived`/`derived_key` mark the three Lease-progress items Johan ruled
 * are system-derived (AT-430 Part D) — the settings screen must render these
 * read-only, never offer a checkbox that would let an agency turn a derived
 * item back into a manual tick. See RentalApplicationChecklistService for
 * what each derived_key resolves to.
 */
class RentalChecklistTemplateItem extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const DERIVED_KEYS = [
        'lease_deposit_paid',
        'lease_first_rent_paid',
        'lease_occupied',
    ];

    protected $fillable = [
        'agency_id', 'template_section_id', 'name', 'help_text',
        'note_required', 'document_required', 'is_derived', 'derived_key', 'sort_order', 'created_by_user_id',
    ];

    protected $casts = [
        'note_required' => 'boolean',
        'document_required' => 'boolean',
        'is_derived' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(RentalChecklistTemplateSection::class, 'template_section_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function nextSortOrderFor(int $sectionId): int
    {
        return (int) (static::withTrashed()->where('template_section_id', $sectionId)->max('sort_order') ?? -1) + 1;
    }
}
