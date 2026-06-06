<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A testimonial a Contact gave, captured on the contact's "Notes &
 * Testimonials" tab and optionally published to the agency's public website.
 *
 * `published` is the single per-record visibility gate (Company Settings →
 * Website). Toggling it fires testimonial.* webhooks via
 * ContactTestimonialObserver → TestimonialVisibilityChanged.
 *
 * Spec: .ai/specs/testimonials.md
 */
class ContactTestimonial extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'contact_id',
        'user_id',
        'agent_id',
        'body',
        'display_name',
        'rating',
        'published',
        'published_at',
        'published_by_user_id',
    ];

    protected $casts = [
        'rating'       => 'integer',
        'published'    => 'boolean',
        'published_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The agent this testimonial is about (shown + linked on the website). */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
