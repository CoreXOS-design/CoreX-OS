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
        // AT-61 — null in address-only mode (contact has a captured structured
        // address but no linked Property). The address source is always present
        // via $address; $property is the richer source when one is linked.
        public readonly ?Property $property,
        public readonly OutreachAddress $address,
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
        // AT-81 — a consent-request is already out and awaiting a reply. A HARD
        // block on re-sending (distinct from optOutBlocks so the agent message is
        // honest: "awaiting reply", not "opted out"). Cleared the moment the
        // contact engages or the no-response window lapses them to opted-out.
        public readonly bool $pendingBlocks = false,
    ) {}

    public function isSendable(): bool
    {
        return empty($this->validationIssues) && !$this->optOutBlocks && !$this->pendingBlocks;
    }

    /** AT-61 — true when composed off a contact address with no linked Property. */
    public function isAddressOnly(): bool
    {
        return $this->property === null;
    }
}
