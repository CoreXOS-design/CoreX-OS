<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;

/**
 * road-to-sold.md 23.23 drop 4: the address of the pitch popup's "Book evaluation" step.
 *
 * It is the calendar's OWN create-event form, opened with the prefill the buyer pipeline already uses (`prefill_class`, `prefill_properties`,
 * `prefill_attendees`): the type "Property evaluation", the property, the owner as the seller, the title; the agent is the creator. `pitch_book=1` tells
 * the calendar page it is inside the popup (the form fills the popup, no calendar behind it) and where to go after saving (the "Evaluation booked" page).
 * Nothing about the form is rebuilt here: saving goes through the calendar's own store(), whose observer links the event to the property and the
 * agent's Road to Sold record (A4, `BeltLinks::linkEvaluation`).
 */
final class PitchBookService
{
    /** @param array<string,string> $carry the popup parameters to keep (embed, rts_prospect, rts_stage) @param array<string,scalar|null> $call a call to record once the evaluation is booked */
    public function url(Property $property, Contact $seller, array $carry = [], array $call = []): string
    {
        $address = trim((string) $property->composeAddressFromParts()) ?: trim((string) $property->address) ?: ('Property #' . $property->id);
        $name = trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? '')) ?: 'Owner';

        $query = array_filter($carry + ['embed' => '1'], fn ($v) => $v !== null && $v !== '') + [
            'pitch_book' => '1',
            'prefill_contact_id' => $seller->id,
            'prefill_class' => 'property_evaluation',
            'prefill_title' => 'Appointment: ' . $address,
            'prefill_properties' => json_encode([['id' => (int) $property->id, 'address' => $address]], JSON_UNESCAPED_UNICODE),
            'prefill_attendees' => json_encode([[
                'id' => (int) $seller->id, 'name' => $name, 'type' => 'contact', 'role' => 'seller_contact',
                'phone' => $seller->phone ?: null, 'email' => $seller->email ?: null,
            ]], JSON_UNESCAPED_UNICODE),
        ];
        foreach (['outcome', 'when', 'note', 'channel'] as $k) {
            if (isset($call[$k]) && $call[$k] !== '') {
                $query['pitch_' . $k] = (string) $call[$k];
            }
        }

        return route('command-center.calendar', $query);
    }
}
