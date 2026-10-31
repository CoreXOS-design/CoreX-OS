<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nightly pocket stats (AT-519 5.5, spec 11.1). Written only by MicStatsBuilder. */
class MicPocketStat extends Model
{
    protected $table = 'mic_pocket_stats';

    protected $guarded = [];

    protected $casts = ['stat_date' => 'date'];
}
