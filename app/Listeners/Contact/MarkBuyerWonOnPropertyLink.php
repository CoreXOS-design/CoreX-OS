<?php

declare(strict_types=1);

namespace App\Listeners\Contact;

use App\Events\Contact\ContactLinkedToProperty;
use App\Services\BuyerStateService;

/**
 * Buyer WON write-back (Johan 2026-08-13, final DR2 build item).
 *
 * When a Contact is linked to a Property as a BUYER — whether via the property page
 * (PropertyContactController) or a DR2 deal's buyer party (DealRegisterController::syncPartyLinks,
 * which fires the same ContactLinkedToProperty event) — reflect the win on the buyer side: mark
 * their buyer_state 'won' and move them out of the active pipeline into the success section.
 *
 * Only acquiring-by-purchase roles count (buyer / purchaser) — a tenant/lessee is a rental, not a
 * buyer-pipeline conversion. Idempotent (BuyerStateService::markWon no-ops if already 'won').
 */
final class MarkBuyerWonOnPropertyLink
{
    /** Roles that represent a buyer converting on a purchase (NOT tenant/lessee — those are rentals). */
    private const BUYER_ROLES = ['buyer', 'purchaser'];

    public function __construct(private readonly BuyerStateService $buyerStates) {}

    public function handle(ContactLinkedToProperty $event): void
    {
        if (! in_array(strtolower(trim($event->role)), self::BUYER_ROLES, true)) {
            return;
        }

        $this->buyerStates->markWon($event->contact, $event->actorUserId);
    }
}
