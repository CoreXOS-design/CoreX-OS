<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An article authored by an agent, shown on their public website profile.
 * Self-service content (My Portal → Profile). Only published articles reach
 * the public website API.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class AgentArticle extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'user_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
