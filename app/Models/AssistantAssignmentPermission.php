<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-267 — one row per permission key in an assistant's matrix.
 *
 * Seeded on assignment as a COPY of the Assigned Agent's permissions (granted = true),
 * except the property-upload locked set. The agent switches things off from their
 * My Assistants page.
 *
 * This model carries BelongsToAgency (so AgencyScope applies to it directly, mirroring
 * viewing_pack_properties) but NOT BelongsToBranch — it is a child of the assignment and
 * inherits the assignment's branch implicitly.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §6.4, §9 layer 4
 */
class AssistantAssignmentPermission extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'assistant_assignment_id',
        'permission_key',
        'granted',
        'scope',
        'is_locked',
        'is_new',
    ];

    protected $casts = [
        'granted'   => 'boolean',
        'is_locked' => 'boolean',
        'is_new'    => 'boolean',
    ];

    protected static function booted(): void
    {
        // Layer 4 of the property-upload lock (spec §9). The resolver already denies
        // locked keys, the controller already strips them from the payload, and the UI
        // renders them disabled — this is the backstop that makes a hand-crafted POST
        // (or a future careless writer) physically unable to persist a granted lock.
        static::saving(function (self $permission) {
            if ($permission->is_locked) {
                $permission->granted = false;
                $permission->scope   = null;
            }
        });
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssistantAssignment::class, 'assistant_assignment_id');
    }
}
