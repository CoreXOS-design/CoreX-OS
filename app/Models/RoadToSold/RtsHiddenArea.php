<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 - a suburb or a whole town an administrator has hidden from Road to Sold (for example a one-off Other Agency Stock
 * import from another province). Soft: restoring brings it back. Spec: .ai/specs/road-to-sold.md section 1 AREAS.
 */
class RtsHiddenArea extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const KIND_SUBURB = 'suburb';
    public const KIND_TOWN   = 'town';

    protected $table = 'rts_hidden_areas';

    protected $fillable = ['agency_id', 'track', 'kind', 'p24_suburb_id', 'p24_city_id', 'hidden_by_user_id', 'reason'];

    protected $casts = ['p24_suburb_id' => 'integer', 'p24_city_id' => 'integer'];
}
