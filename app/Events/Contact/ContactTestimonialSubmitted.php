<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use App\Models\ContactTestimonial;

/**
 * Fires when a CLIENT submits a testimonial about their agent from the mobile
 * app (POST /api/v1/client/testimonials). This is the cross-pillar signal that
 * Contact → Agent: the connected agent is notified (in-app + email) that their
 * client left them a testimonial.
 *
 * NOT emitted when an agent captures a testimonial themselves on the web — that
 * path is silent (the agent already knows). Visibility/website fan-out is a
 * separate concern handled by TestimonialVisibilityChanged on publish.
 *
 * Spec: .ai/specs/testimonials.md §13 (client submission).
 */
final class ContactTestimonialSubmitted extends AbstractDomainEvent
{
    public function __construct(
        public readonly ContactTestimonial $testimonial,
        public readonly Contact $contact,
        public readonly ?int $agentUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->testimonial->agency_id ?? null; }
    public function subject(): ?array { return [ContactTestimonial::class, $this->testimonial->id]; }
}
