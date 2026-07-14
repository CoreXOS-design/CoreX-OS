<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
                // AT-246 — stamp the first-class owner column too, not just the
                // source_ref string. Mirrors logDistribution() (below). Without this
                // the sender's own manual sends are invisible to them (scopeVisibleTo
                // excludes NULL-owner rows) and render as "Agent"/"Unassigned". A null
                // $userId (no sending agent) legitimately stays null — an ownerless
                // provisional row, same graceful-null contract as mailbox ingest.
                'owner_user_id'           => $userId,
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

    /**
     * AT-158 WS3/WS4 (§10) — record a DR2 document distribution as a provisional
     * outbound communication, on ALL THREE pillars.
     *
     * Extends the sanctioned seam (same provisional/reconciler mechanics) but
     * for a distribution the recipient may be a directory PROVIDER (no Contact),
     * so it takes an email + a set of link models rather than a single Contact.
     * Improvements over log(): stamps owner_user_id at create (AT-122
     * provenance), writes communication_attachments for the pack, and writes a
     * link row PER pillar (Contact + Property + DealV2) instead of a hardcoded
     * Contact link. ProvisionalReconciler promotes it in place if the Sent copy
     * is later ingested.
     *
     * @param Model[] $linkModels  Contact / Property / DealV2 instances to link
     * @param array<int,array{storage_path:string,disk?:string,filename?:string,mime?:string,size?:int}> $attachments
     */
    public function logDistribution(
        int $agencyId,
        ?int $ownerUserId,
        string $recipientEmail,
        ?string $subject,
        ?string $body,
        array $linkModels = [],
        array $attachments = [],
        string $channel = Communication::CHANNEL_EMAIL   // AT-228 — email|whatsapp
    ): Communication {
        $now      = now();
        $textHash = MessageTextHasher::hash($channel, $subject, $body);

        return DB::transaction(function () use (
            $agencyId, $ownerUserId, $recipientEmail, $subject, $body,
            $linkModels, $attachments, $now, $channel, $textHash
        ) {
            $communication = Communication::create([
                'agency_id'               => $agencyId,
                'channel'                 => $channel,
                'direction'               => Communication::DIRECTION_OUTBOUND,
                'external_id'             => 'provisional:' . Str::uuid()->toString(),
                'thread_key'              => null,
                'from_identifier'         => null,
                'participant_identifiers' => array_values(array_filter([$recipientEmail])),
                'occurred_at'             => $now,
                'captured_at'             => $now,
                'provisional_at'          => $now,
                'subject'                 => $subject !== null ? Str::limit((string) $subject, 1000, '') : null,
                'body_text'               => $body,
                'body_preview'            => $body !== null ? Str::limit((string) $body, 160) : null,
                'raw_path'                => null,
                'content_hash'            => null,
                'text_hash'               => $textHash,
                'has_attachments'         => count($attachments) > 0,
                'source_ref'              => 'deal_distribution:user:' . ($ownerUserId ?? 'unknown'),
                'owner_user_id'           => $ownerUserId,
            ]);

            // One link row per pillar — Contact (if a contact recipient),
            // Property (the deal's property) and DealV2 (the deal). The morph
            // supports all three; historic writers only ever wrote Contact.
            foreach ($linkModels as $model) {
                if (! $model instanceof Model) {
                    continue;
                }
                CommunicationLink::create([
                    'agency_id'        => $agencyId,
                    'communication_id' => $communication->id,
                    'linkable_type'    => $model->getMorphClass(),
                    'linkable_id'      => $model->getKey(),
                    'link_method'      => CommunicationLink::METHOD_MANUAL,
                    'confirmed_at'     => null,
                ]);
            }

            foreach ($attachments as $att) {
                $disk = $att['disk'] ?? 'local';
                $path = $att['storage_path'] ?? null;
                if (! $path) {
                    continue;
                }
                // content_hash is NOT NULL — derive from the actual bytes.
                $hash = '';
                try {
                    if (Storage::disk($disk)->exists($path)) {
                        $hash = hash('sha256', Storage::disk($disk)->get($path));
                    }
                } catch (\Throwable $e) {
                    $hash = '';
                }
                CommunicationAttachment::create([
                    'agency_id'        => $agencyId,
                    'communication_id' => $communication->id,
                    'filename'         => $att['filename'] ?? basename($path),
                    'mime'             => $att['mime'] ?? 'application/pdf',
                    'size_bytes'       => (int) ($att['size'] ?? 0),
                    'content_hash'     => $hash !== '' ? $hash : str_repeat('0', 64),
                    'storage_path'     => $path,
                ]);
            }

            return $communication;
        });
    }
}
