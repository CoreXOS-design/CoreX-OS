<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a PROVISIONAL outbound communication when an agent clicks WhatsApp /
 * Email on a contact (AT-59). The real message has not been ingested yet — this
 * row gives the comms tile instant, truthful feedback ("1 message sent") and is
 * later RECONCILED in place by the ingestors (promoted to a confirmed archive
 * record) so the same send is never counted twice.
 *
 * The row carries a normalised text_hash (MessageTextHasher) so ingestion can
 * match it deterministically, and a placeholder external_id ("provisional:<uuid>")
 * so it satisfies the (agency_id, external_id) unique index without colliding —
 * promotion overwrites it with the real Message-ID / WA id.
 */
class OutboundProvisionalLogger
{
    /**
     * @param Contact     $contact the recipient (the tile's contact)
     * @param string      $channel Communication::CHANNEL_EMAIL|CHANNEL_WHATSAPP
     * @param string|null $subject email subject (null for WhatsApp)
     * @param string|null $body    composed message text
     * @param int|null    $userId  the sending agent (audit trail)
     */
    public function log(Contact $contact, string $channel, ?string $subject, ?string $body, ?int $userId = null): Communication
    {
        $agencyId = (int) $contact->agency_id;
        $now      = now();
        $textHash = MessageTextHasher::hash($channel, $subject, $body);

        return DB::transaction(function () use ($contact, $agencyId, $channel, $subject, $body, $userId, $now, $textHash) {
            $communication = Communication::create([
                'agency_id'               => $agencyId,
                'channel'                 => $channel,
                'direction'               => Communication::DIRECTION_OUTBOUND,
                'external_id'             => 'provisional:' . Str::uuid()->toString(),
                'thread_key'              => null,
                'from_identifier'         => null,
                'participant_identifiers' => array_values(array_filter([
                    $channel === Communication::CHANNEL_EMAIL ? $contact->email : $contact->phone,
                ])),
                'occurred_at'             => $now,
                'captured_at'             => $now,
                'provisional_at'          => $now,
                'subject'                 => $channel === Communication::CHANNEL_EMAIL
                    ? ($subject !== null ? Str::limit((string) $subject, 1000, '') : null)
                    : null,
                'body_text'               => $body,
                'body_preview'            => $body !== null ? Str::limit((string) $body, 160) : null,
                'raw_path'                => null,
                'content_hash'            => null,
                'text_hash'               => $textHash,
                'has_attachments'         => false,
                'source_ref'              => 'manual:user:' . ($userId ?? 'unknown'),
            ]);

            CommunicationLink::create([
                'agency_id'        => $agencyId,
                'communication_id' => $communication->id,
                'linkable_type'    => Contact::class,
                'linkable_id'      => $contact->id,
                'link_method'      => CommunicationLink::METHOD_MANUAL,
                // Provisional: not yet a confirmed archive fact — confirmed_at is
                // set when reconciliation promotes the row.
                'confirmed_at'     => null,
            ]);

            $contact->touchLastContacted($now);

            return $communication;
        });
    }
}
