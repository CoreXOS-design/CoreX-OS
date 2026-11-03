<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Road to Sold v3 - why an agent set a property aside (road-to-sold.md section 22.4). An agency list, seeded neutral, edited in Recipe. */
class RtsParkReason extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'rts_park_reasons';

    protected $fillable = [
        'agency_id', 'track', 'station', 'step', 'kind', 'counts_as', 'is_other', 'key', 'label', 'default_revisit_days', 'is_system', 'is_active', 'sort_order', 'updated_by_user_id',
    ];

    protected $casts = [
        'default_revisit_days' => 'integer',
        'is_system'            => 'boolean',
        'is_other'             => 'boolean',
        'step'                 => 'integer',
        'is_active'            => 'boolean',
        'sort_order'           => 'integer',
    ];

    public const KIND_OFF_RAMP = 'off_ramp';
    public const KIND_BLOCKER = 'blocker';

    /** v4 A6: station / kind are columns added by migration; until they exist every reason is an "any station" off-ramp, as before. */
    public static function hasStationColumns(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'station');
    }

    /** v4 (23.25): step / counts_as / is_other are columns added by migration; until they exist the popup has no reason lists (the station reasons carry on as before). */
    public static function hasStepColumns(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'step');
    }

    public const COUNTS_AS = ['not_selling' => 'Not selling', 'lost' => 'Lost'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
