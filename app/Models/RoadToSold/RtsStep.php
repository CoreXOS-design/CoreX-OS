<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 1 - one step of an agency's recipe (road-to-sold.md section 5.4). The 13 steps are a fixed catalogue
 * (RecipeCatalogue::STEPS); the agency may rename, switch off, reorder (inside its group) and archive them.
 * Written only through RecipeService.
 */
class RtsStep extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const GROUP_GET_STOCK  = 'get_stock';
    public const GROUP_SELL_STOCK = 'sell_stock';

    protected $table = 'rts_steps';

    protected $fillable = ['agency_id', 'track', 'key', 'group', 'position', 'label', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean', 'position' => 'integer'];

    public function tasks(): HasMany
    {
        return $this->hasMany(RtsTask::class, 'step_id');
    }
}
