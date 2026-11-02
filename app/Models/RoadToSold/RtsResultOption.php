<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Road to Sold v4 (road-to-sold.md 23.10) - one answer to "what happened at the evaluation / presentation": won, undecided, lost (why), not selling.
 * ONE list for the calendar's evaluation feedback, the presentation outcome panel and the belt card. An agency list, seeded neutral, soft deleted only.
 */
class RtsResultOption extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const CLASS_WON = 'won';
    public const CLASS_PENDING = 'pending';
    public const CLASS_LOST = 'lost';
    public const CLASS_NOT_SELLING = 'not_selling';
    public const CLASSES = [self::CLASS_WON, self::CLASS_PENDING, self::CLASS_LOST, self::CLASS_NOT_SELLING];

    protected $table = 'rts_result_options';

    protected $fillable = [
        'agency_id', 'track', 'key', 'label', 'class', 'park_reason_key', 'needs_date', 'revisit_days', 'is_system', 'is_active', 'sort_order', 'updated_by_user_id',
    ];

    protected $casts = [
        'needs_date' => 'boolean', 'revisit_days' => 'integer', 'is_system' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer',
    ];
}
