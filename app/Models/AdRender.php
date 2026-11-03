<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-525 / AT-527 (spec 9.16.1) - one render of a video ad in one format. Platform record (CoreX's own marketing): no agency. */
class AdRender extends Model
{
    use SoftDeletes;

    public const FORMATS = ['9:16', '1:1', '16:9'];

    protected $guarded = [];
    protected $casts = ['rendered_at' => 'datetime', 'duration_seconds' => 'float'];
}
