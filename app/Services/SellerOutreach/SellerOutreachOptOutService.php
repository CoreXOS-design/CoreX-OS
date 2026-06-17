<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use Illuminate\Support\Facades\Auth;

/**
 * Records a seller's opt-out request. The event listener
 * RecordOptOutOnContact actually flips messaging_opt_out_at on the contact;
 * this service is the boundary that fires the event after asserting tenancy.
 */
final class SellerOutreachOptOutService
{
    /**
     * @param string|null $source Provenance marker for the opt-out. NULL (the
     *   default) means an agent recorded it and the recorder is carried by
     *   Auth::id(); pass OptOutRecorded::SOURCE_SELF_SERVICE_LINK for the public
     *   per-send opt-out link, where there is no authenticated user (AT-49).
     *
     * @throws \InvalidArgumentException if contact or send is in another agency
     */
    public function recordOptOut(
        int $agencyId,
        Contact $contact,
        string $reason,
        ?SellerOutreachSend $send = null,
        ?string $source = null,
        bool $blockAll = true,
    ): void {
        if ((int) $contact->agency_id !== $agencyId) {
            throw new \InvalidArgumentException("Contact {$contact->id} is not in agency {$agencyId}.");
        }
        if ($send !== null && (int) $send->agency_id !== $agencyId) {
            throw new \InvalidArgumentException("Send {$send->id} is not in agency {$agencyId}.");
        }

        event(new OptOutRecorded(
            contact: $contact,
            send: $send,
            reason: $reason,
            actorUserId: Auth::id(),
            agencyId: $agencyId,
            source: $source,
            blockAll: $blockAll,
        ));
    }
}
