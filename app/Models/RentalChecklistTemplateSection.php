<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-430 §3.2/§3.3 — the agency's own checklist section (e.g. "Vetting",
 * "FICA"). Archive-only, never hard-deleted, same shape as
 * RentalApplicationDeclineReasonTemplate. Editing this after an application
 * exists never rewrites that application — see RentalApplicationChecklist
 * Section for the per-application snapshot.
 */
class RentalChecklistTemplateSection extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = ['agency_id', 'name', 'sort_order', 'created_by_user_id'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RentalChecklistTemplateItem::class, 'template_section_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function nextSortOrderFor(int $agencyId): int
    {
        return (int) (static::withTrashed()->where('agency_id', $agencyId)->max('sort_order') ?? -1) + 1;
    }
}
