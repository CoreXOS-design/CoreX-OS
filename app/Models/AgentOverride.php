<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Build 2 — agent_overrides log.
 *
 * Append-only log of changes the agent makes on the review screen.
 * Pure audit / future-learning surface — no business logic reads from
 * this table this build.
 *
 * override_type enum (see migration for full list):
 *   comp_excluded, comp_included, category_added, category_removed,
 *   condition_changed, section_toggled, field_edited,
 *   review_takeover, comp_unavailable.
 */
class AgentOverride extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'presentation_version_id',
        'user_id',
        'override_type',
        'target_id',
        'before_value',
        'after_value',
    ];

    protected $casts = [
        'before_value' => 'array',
        'after_value'  => 'array',
    ];

    // override_type constants — keep in sync with the migration enum.
    public const TYPE_COMP_EXCLUDED     = 'comp_excluded';
    public const TYPE_COMP_INCLUDED     = 'comp_included';
    public const TYPE_COMP_BULK_SET     = 'comp_bulk_set';   // AT-22 — slider/select-all/bulk curation
    public const TYPE_COMP_ADDED        = 'comp_added';      // AT-22 — pulled in from browse-beyond
    public const TYPE_CATEGORY_ADDED    = 'category_added';
    public const TYPE_CATEGORY_REMOVED  = 'category_removed';
    public const TYPE_CONDITION_CHANGED = 'condition_changed';
    public const TYPE_SECTION_TOGGLED   = 'section_toggled';
    public const TYPE_FIELD_EDITED      = 'field_edited';
    public const TYPE_REVIEW_TAKEOVER   = 'review_takeover';
    public const TYPE_COMP_UNAVAILABLE  = 'comp_unavailable';

    public function presentationVersion()
    {
        return $this->belongsTo(PresentationVersion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
