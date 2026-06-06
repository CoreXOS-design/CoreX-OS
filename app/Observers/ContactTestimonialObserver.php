<?php

namespace App\Observers;

use App\Events\Website\TestimonialVisibilityChanged;
use App\Models\ContactTestimonial;
use Illuminate\Support\Facades\Log;

/**
 * Agency Public API — emit testimonial.* webhooks when a testimonial's website
 * presence changes. Guarded (only fires on a real publish transition or a
 * public-content change of a published testimonial) and failure-isolated so it
 * never breaks a save.
 *
 * Spec: .ai/specs/testimonials.md §5.
 */
class ContactTestimonialObserver
{
    /** Public-content fields exposed by the website TestimonialResource. */
    private const PUBLIC_FIELDS = ['body', 'display_name', 'rating', 'agent_id'];

    public function created(ContactTestimonial $testimonial): void
    {
        try {
            if ($testimonial->published) {
                event(new TestimonialVisibilityChanged($testimonial, 'published'));
            }
        } catch (\Throwable $e) {
            Log::warning("Testimonial website webhook (create) failed for #{$testimonial->id}: {$e->getMessage()}");
        }
    }

    public function updated(ContactTestimonial $testimonial): void
    {
        try {
            // publish flag flipped → published / removed.
            if ($testimonial->wasChanged('published')) {
                event(new TestimonialVisibilityChanged(
                    $testimonial,
                    $testimonial->published ? 'published' : 'removed'
                ));
                return;
            }

            // A published testimonial's public content changed → updated.
            if ($testimonial->published && $testimonial->wasChanged(self::PUBLIC_FIELDS)) {
                event(new TestimonialVisibilityChanged($testimonial, 'updated'));
            }
        } catch (\Throwable $e) {
            Log::warning("Testimonial website webhook (update) failed for #{$testimonial->id}: {$e->getMessage()}");
        }
    }

    public function deleted(ContactTestimonial $testimonial): void
    {
        try {
            if ($testimonial->published) {
                event(new TestimonialVisibilityChanged($testimonial, 'removed'));
            }
        } catch (\Throwable $e) {
            Log::warning("Testimonial website webhook (delete) failed for #{$testimonial->id}: {$e->getMessage()}");
        }
    }
}
