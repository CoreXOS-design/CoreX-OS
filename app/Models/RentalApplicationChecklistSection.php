<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AT-430 §3.2 — the per-application SNAPSHOT of a checklist section, taken
 * once at application creation by RentalApplicationChecklistService. `name`
 * is copied at snapshot time — a later template rename never rewrites an
 * application already in flight.
 *
 * `description` is Johan's one free-text box PER SECTION (verbatim: "each
 * section has a desc where agents can type in what they find") — a note on
 * THIS application, never on the template.
 */
class RentalApplicationChecklistSection extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'rental_application_id', 'template_section_id', 'name', 'sort_order', 'description',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RentalApplicationChecklistItem::class, 'application_section_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    public function templateSection(): BelongsTo
    {
        return $this->belongsTo(RentalChecklistTemplateSection::class, 'template_section_id');
    }

    /** §3.5 — done/total excluding not_applicable. Used by both the section header and the panel-level overall count. */
    public function progress(): array
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $countable = $items->where('state', '!=', RentalApplicationChecklistItem::STATE_NOT_APPLICABLE);

        return [
            'done' => $countable->where('state', RentalApplicationChecklistItem::STATE_DONE)->count(),
            'total' => $countable->count(),
        ];
    }
}
