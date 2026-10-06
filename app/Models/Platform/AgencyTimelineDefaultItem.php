<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An editable DEFAULT info block / milestone for new agency timelines
 * (Dev Settings → Agency timeline defaults). Platform-owned, not tenant-scoped.
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §7.2
 */
class AgencyTimelineDefaultItem extends Model
{
    use SoftDeletes;

    public const KIND_BLOCK = 'block';
    public const KIND_MILESTONE = 'milestone';

    /** Auto-complete triggers an item may carry (spec §9). */
    public const TRIGGERS = [
        'contract_signed'        => 'When the agency signs the contract',
        'setup_wizard_completed' => 'When the agency finishes the setup wizard',
    ];

    protected $table = 'agency_timeline_default_items';

    protected $fillable = [
        'kind', 'title', 'body', 'sort_order', 'offset_days',
        'is_public', 'agency_can_complete', 'is_go_live', 'auto_complete_trigger',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'offset_days' => 'integer',
        'is_public'   => 'boolean',
        'agency_can_complete' => 'boolean',
        'is_go_live'  => 'boolean',
    ];
}
