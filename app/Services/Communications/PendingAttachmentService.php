<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;

/**
 * Shared retroactive-attach path (AT-36). Moves matured pending items into the
 * permanent archive + records the deterministic contact link. Extracted from
 * PruneCommunicationPending so the nightly pruner AND the triage "Add contact"
 * action use one implementation.
 */
class PendingAttachmentService
{
    /**
     * Attach one pending item to the archive (dedup on agency_id + external_id),
     * link it to the contact, and soft-purge the pending row.
     */
    public function attach(CommunicationPending $pending, Contact $contact): void
    {
        $exists = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $pending->agency_id)
            ->where('external_id', $pending->external_id)
            ->exists();

        if (! $exists) {
            $comm = Communication::create([
                'agency_id'               => $pending->agency_id,
                'channel'                 => $pending->channel,
                'direction'               => $pending->direction,
                'external_id'             => $pending->external_id,
                'thread_key'              => $pending->thread_key,
                'from_identifier'         => $pending->from_identifier,
                'participant_identifiers' => $pending->participant_identifiers,
                'occurred_at'             => $pending->occurred_at,
                'captured_at'             => $pending->captured_at,
                'subject'                 => $pending->subject,
                'body_text'               => $pending->body_text,
                'body_preview'            => $pending->body_preview,
                'raw_path'                => $pending->raw_path,
                'has_attachments'         => $pending->has_attachments,
                'content_hash'            => $pending->content_hash,
                'source_ref'              => $pending->source_ref,
            ]);

            CommunicationLink::create([
                'agency_id'        => $pending->agency_id,
                'communication_id' => $comm->id,
                'linkable_type'    => Contact::class,
                'linkable_id'      => $contact->id,
                'link_method'      => CommunicationLink::METHOD_DETERMINISTIC,
                'confidence'       => 100,
                'confirmed_at'     => now(),
            ]);
        }

        $pending->update(['purged_at' => now(), 'purged_reason' => 'attached_to_archive']);
        $pending->delete(); // soft
    }

    /**
     * Attach every live pending item whose sender resolves to the given
     * identifier (normalised match) into the archive. Returns the count attached.
     * Triggered by the triage "Add contact" action.
     */
    public function attachAllForIdentifier(int $agencyId, string $identifier, Contact $contact): int
    {
        $normalized = IdentifierNormalizer::normalize($identifier);
        $count = 0;

        CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('purged_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($normalized, $contact, &$count) {
                foreach ($rows as $pending) {
                    if (IdentifierNormalizer::normalize((string) $pending->from_identifier) === $normalized) {
                        $this->attach($pending, $contact);
                        $count++;
                    }
                }
            });

        return $count;
    }
}
