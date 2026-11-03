<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-454 v2 phase 3 - the moment an agency moved its daily-activity points to the Road to Sold task completions, once per agency and
 * track, with what the move carried (road-to-sold.md section 7.4). An agency with a row here reads its points from the completions;
 * the row is written by DailyActivityMigrationService and never edited.
 */
class RtsCutover extends Model
{
    use BelongsToAgency;

    protected $table = 'rts_cutovers';

    protected $fillable = ['agency_id', 'track', 'cut_over_at', 'report'];

    protected $casts = ['cut_over_at' => 'datetime', 'report' => 'array'];
}
