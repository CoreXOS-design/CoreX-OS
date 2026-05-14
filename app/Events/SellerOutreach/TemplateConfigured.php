<?php

declare(strict_types=1);

namespace App\Events\SellerOutreach;

use App\Events\AbstractDomainEvent;
use App\Models\SellerOutreach\SellerOutreachTemplate;

/**
 * Fires when a seller-outreach template is created, updated, archived, or restored.
 * Subscribers (Phase 1): wildcard audit only.
 */
final class TemplateConfigured extends AbstractDomainEvent
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_ARCHIVED = 'archived';
    public const ACTION_RESTORED = 'restored';

    public function __construct(
        public readonly SellerOutreachTemplate $template,
        public readonly string $action,
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [SellerOutreachTemplate::class, $this->template->id];
    }

    public function context(): array
    {
        return [
            'action' => $this->action,
            'name' => $this->template->name,
            'channel' => $this->template->channel,
            'is_default_for_channel' => (bool) $this->template->is_default_for_channel,
        ];
    }
}
