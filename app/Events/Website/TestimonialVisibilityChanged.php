<?php

declare(strict_types=1);

namespace App\Events\Website;

use App\Events\AbstractDomainEvent;
use App\Models\ContactTestimonial;

/**
 * A testimonial's website presence changed and agency websites should be
 * notified.
 *
 *   published → the publish tick turned on (Company Settings → Website)
 *   updated   → a published testimonial's public content changed
 *   removed   → the publish tick turned off, or it was soft-deleted
 *
 * Testimonials are agency-wide (not per-property), so the listener fans out to
 * every website key of the testimonial's agency.
 * Spec: .ai/specs/testimonials.md §5.
 */
class TestimonialVisibilityChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly ContactTestimonial $testimonial,
        public readonly string $action,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->testimonial->agency_id ? (int) $this->testimonial->agency_id : null;
    }

    public function subject(): ?array
    {
        return [ContactTestimonial::class, $this->testimonial->id];
    }

    public function webhookEvent(): string
    {
        return match ($this->action) {
            'published' => 'testimonial.published',
            'removed'   => 'testimonial.removed',
            default     => 'testimonial.updated',
        };
    }
}
