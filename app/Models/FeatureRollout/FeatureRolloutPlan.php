<?php

namespace App\Models\FeatureRollout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named, complete set of rollout groups (Dev Settings → Feature rollout). Platform-level, owner-only, no agency.
 * An agency that starts from a plan gets its own copy of the groups, so editing a plan never moves a running one.
 * Spec: agency-feature-rollout.md §22.
 */
class FeatureRolloutPlan extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'any'     => 'Any agency',
        'sales'   => 'Sales',
        'rentals' => 'Rentals',
        'both'    => 'Sales and rentals',
    ];

    protected $fillable = ['name', 'note', 'agency_type', 'is_default', 'created_by'];

    protected $casts = ['is_default' => 'boolean'];

    /** The default plan's id; if none exists yet (fresh database) the "Standard" plan is created. */
    public static function defaultId(): int
    {
        $id = static::where('is_default', true)->value('id') ?: static::orderBy('id')->value('id');

        return (int) ($id ?: static::create(['name' => 'Standard', 'agency_type' => 'any', 'is_default' => true])->id);
    }

    public function waves(): HasMany
    {
        return $this->hasMany(FeatureRolloutWave::class, 'plan_id');
    }
}
