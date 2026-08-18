<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\TrackedPropertyComment;

/**
 * Fires when a user adds a comment to a TrackedProperty via the MIC Work-tab
 * row comment chip. No listeners yet — lands per mic-complete-spec.md §2.4
 * ("every action emits a domain event"), same pattern as
 * TrackedPropertyAddressVerified. Spec: .ai/specs/mic-property-row-comments.md
 */
final class TrackedPropertyCommentAdded extends AbstractDomainEvent
{
    public function __construct(
        public readonly TrackedPropertyComment $comment,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->comment->agency_id; }
    public function actorUserId(): ?int { return (int) $this->comment->user_id; }

    public function subject(): ?array
    {
        return [TrackedPropertyComment::class, (int) $this->comment->id];
    }

    public function context(): array
    {
        return [
            'tracked_property_id' => (int) $this->comment->tracked_property_id,
        ];
    }
}
