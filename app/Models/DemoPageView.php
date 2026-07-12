<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single page a prospect looked at inside the demo.
 *
 * Spec: .ai/specs/demo-access-control.md §4.5
 *
 * Written on PRIMARY from a queued job on the demo host. The whole write path
 * FAILS OPEN — a demo page must never block, slow, or error because this row
 * could not be inserted.
 */
class DemoPageView extends Model
{
    protected $fillable = [
        'demo_session_id',
        'path',
        'route_name',
        'title',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(DemoSession::class, 'demo_session_id');
    }
}
