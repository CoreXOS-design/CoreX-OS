<?php

declare(strict_types=1);

namespace App\Support\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;

/**
 * Composed pitch context — everything the composer UI and the sender service
 * need to render a preview and execute a send. Returned by
 * SellerOutreachComposerService::composeContext().
 */
final class OutreachContext
{
    public function __construct(
        public readonly Contact $contact,
        public readonly Property $property,
        public readonly User $agent,
        public readonly int $agencyId,
        public readonly ?SellerOutreachTemplate $template,
        public readonly string $channel,
        public readonly array $mergeFields,
        public readonly array $factsSnapshot,
        public readonly ?string $renderedSubject,
        public readonly string $renderedBody,
        public readonly ?string $recipientPhone,
        public readonly ?string $recipientEmail,
        public readonly array $validationIssues,
        public readonly bool $optOutBlocks,
        public readonly ?array $cooldownSignal,
    ) {}

    public function isSendable(): bool
    {
        return empty($this->validationIssues) && !$this->optOutBlocks;
    }
}
