<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Stored per-file counts (AT-519 5.5, spec 11.1). Written only by MicStatsBuilder; read by the Work list and tiles. */
class MicFileStat extends Model
{
    protected $table = 'mic_file_stats';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    public $timestamps = false;
}
