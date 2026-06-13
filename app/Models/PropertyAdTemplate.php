<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PropertyAdTemplate extends Model
{
    use BelongsToAgency, SoftDeletes;


    protected $fillable = [
        'agency_id','user_id', 'name', 'layout_json', 'is_global'];

    protected $casts = [
        'layout_json' => 'array',
        'is_global'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Edit/delete rights (spec ad-manager.md §6):
     * the original creator always qualifies; any other member needs the
     * `properties.ad_templates.manage` permission. Cross-agency access is
     * already blocked by AgencyScope (route-model binding 404s), so this
     * only ever decides rights within the same agency.
     */
    public function canBeManagedBy(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        return $user->hasPermission('properties.ad_templates.manage');
    }
}
