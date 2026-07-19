<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// AT-284 — one capture session's run-log row. No hard delete.
class MinionCaptureRun extends Model
{
    use SoftDeletes;

    protected $table = 'minion_capture_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
        'failures_json' => 'array',
    ];
}
