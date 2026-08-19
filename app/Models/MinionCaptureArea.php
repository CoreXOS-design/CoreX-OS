<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// AT-284 — one ticked p24 suburb in an agency's capture universe. No hard delete.
class MinionCaptureArea extends Model
{
    use SoftDeletes;

    protected $table = 'minion_capture_areas';

    protected $guarded = ['id'];

    protected $casts = [
        'last_captured_at' => 'datetime',
    ];
}
