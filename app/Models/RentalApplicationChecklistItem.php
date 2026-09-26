<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AT-430 §3.2 — the per-application SNAPSHOT of a checklist item. Copied
 * from the template at creation, then lives its own life: state/note/who/
 * when are set as the agent works the file, independent of any later
 * template edit.
 *
 * Derived items (is_derived=true): state/note/set_at are written ONLY by
 * RentalApplicationChecklistService::syncDerivedStates() — no controller
 * route accepts a manual state write for a derived item (AT-430 Part D:
 * "do NOT let an agent set them by hand").
 */
class RentalApplicationChecklistItem extends Model
{
    use BelongsToAgency;

    public const STATE_NOT_STARTED = 'not_started';
    public const STATE_DONE = 'done';
    public const STATE_NOT_APPLICABLE = 'not_applicable';

    public const STATES = [self::STATE_NOT_STARTED, self::STATE_DONE, self::STATE_NOT_APPLICABLE];

    protected $fillable = [
        'agency_id', 'application_section_id', 'template_item_id',
        'name', 'help_text', 'note_required', 'document_required', 'is_derived', 'derived_key',
        'state', 'note', 'set_by_user_id', 'set_at', 'sort_order',
    ];

    protected $casts = [
        'note_required' => 'boolean',
        'document_required' => 'boolean',
        'is_derived' => 'boolean',
        'sort_order' => 'integer',
        'set_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(RentalApplicationChecklistSection::class, 'application_section_id');
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(RentalChecklistTemplateItem::class, 'template_item_id');
    }

    public function setByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    /**
     * AT-430 Part E — evidence attached directly on this item, tagged (not
     * duplicated) onto the SAME row that also lives in the application's
     * generic Supporting Documents list (see the migration that adds
     * `checklist_item_id` to `documents`). Trashed rows excluded by
     * default — callers that need archived attachments too (the item's own
     * "Removed" disclosure) use withTrashed() explicitly.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'checklist_item_id');
    }
}
