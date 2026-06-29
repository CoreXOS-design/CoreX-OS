<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The testable core of the email adapter (AT-33). Takes one already-extracted
 * message and writes it into the spine: dedup on Message-ID, raw .eml +
 * attachments through the content-addressed storage writer, known-contact gate
 * via ContactIdentifierResolver (match → archive + deterministic link; no match
 * → inbound grace buffer). No IMAP here — the poller feeds it normalized data,
 * so the dedup/gate paths are unit-testable without a live server.
 */
class EmailArchiveIngestor
{
    public const RESULT_ARCHIVED    = 'archived';
    public const RESULT_RECONCILED  = 'reconciled';
    public const RESULT_PENDING     = 'pending';
    public const RESULT_DUPLICATE   = 'duplicate';
    public const RESULT_DROPPED     = 'dropped';

    public function __construct(
        private CommunicationStorageService $storage,
        private ContactIdentifierResolver $resolver,
        private CommunicationIngestFilter $ingestFilter,
        private ProvisionalReconciler $reconciler,
    ) {
    }

    /**
     * @param array $msg keys: external_id, thread_key, from, counterpart,
     *                   participants[], subject, body_text, occurred_at (Carbon),
     *                   raw (string), attachments[] (each: filename, mime, bytes)
     * @param string $direction Communication::DIRECTION_INBOUND|OUTBOUND
     */
    public function ingest(CommunicationMailbox $mailbox, array $msg, string $direction): string
    {
        $agencyId = (int) $mailbox->agency_id;
        $externalId = (string) ($msg['external_id'] ?? '');
        if ($externalId === '') {
            // No Message-ID — derive a stable id from the raw bytes so re-polls dedup.
            $externalId = 'sha256:' . hash('sha256', (string) ($msg['raw'] ?? Str::uuid()->toString()));
        }

        if ($this->alreadySeen($agencyId, $externalId)) {
            return self::RESULT_DUPLICATE;
        }

        $attachments = $msg['attachments'] ?? [];
        $counterpart = (string) ($msg['counterpart'] ?? $msg['from'] ?? '');

        // AT-122 — MATCH-FIRST, store-only-on-match. Resolve the counterpart to a
        // known contact BEFORE anything touches disk or the DB. An email that
        // matches no existing contact is DISCARDED outright — never written to
        // the archive AND never parked in communication_pending (the old
        // store-then-match grace buffer is gone). The known-contact gate is the
        // import boundary: no contact, no record.
        $contact = $counterpart !== '' ? $this->resolver->resolve($counterpart, $agencyId) : null;

        if (! $contact) {
            // No contact match → discard. The never-business filter (AT-43, POPIA
            // minimisation) still classifies WHY for the audit line, but under
            // match-only every unmatched message is dropped regardless — nothing
            // is written to disk or any table.
            $dropReason = $this->ingestFilter->dropReasonForUnknown($counterpart, $mailbox->agency)
                ?? 'no_contact_match';

            Log::info('Communication archive: ingestion dropped (not stored)', [
                'agency_id'   => $agencyId,
                'mailbox_id'  => $mailbox->id,
                'channel'     => Communication::CHANNEL_EMAIL,
                'direction'   => $direction,
                'sender'      => $counterpart,
                'reason'      => $dropReason,
                'occurred_at' => optional($msg['occurred_at'] ?? null)?->toIso8601String(),
                'dropped_at'  => now()->toIso8601String(),
            ]);

            return self::RESULT_DROPPED;
        }

        // Matched → now (and only now) persist the raw .eml and build the index row.
        $stored = $this->storage->store($agencyId, 'email', (string) ($msg['raw'] ?? ''));

        $common = [
            'agency_id'              => $agencyId,
            'channel'                => Communication::CHANNEL_EMAIL,
            'direction'              => $direction,
            'external_id'            => $externalId,
            'thread_key'             => $msg['thread_key'] ?? null,
            'from_identifier'        => $msg['from'] ?? null,
            'participant_identifiers' => array_values($msg['participants'] ?? []),
            'occurred_at'            => $msg['occurred_at'] ?? now(),
            'captured_at'            => now(),
            'subject'                => isset($msg['subject']) ? Str::limit((string) $msg['subject'], 1000, '') : null,
            'body_text'              => $msg['body_text'] ?? null,
            'body_preview'           => isset($msg['body_text']) ? Str::limit((string) $msg['body_text'], 160) : null,
            'raw_path'               => $stored['path'],
            'content_hash'           => $stored['content_hash'],
            'text_hash'              => MessageTextHasher::hash(
                Communication::CHANNEL_EMAIL,
                $msg['subject'] ?? null,
                $msg['body_text'] ?? null
            ),
            'has_attachments'        => count($attachments) > 0,
            'source_ref'             => 'mailbox:' . $mailbox->id,
            // AT-122 — provenance: the agent whose mailbox ingested this. Nullable
            // (agency-level mailboxes have no owner). Provenance only — not gated.
            'owner_user_id'          => $mailbox->user_id,
        ];

        return DB::transaction(function () use ($contact, $direction, $common, $attachments, $agencyId, $mailbox) {
            // AT-59: an outbound message may already exist as a provisional row
            // from the agent's click. Promote it in place instead of duplicating.
            if ($direction === Communication::DIRECTION_OUTBOUND) {
                $promoted = $this->reconciler->reconcileOutbound(
                    $contact,
                    Communication::CHANNEL_EMAIL,
                    $common,
                    $mailbox->agency
                );

                if ($promoted) {
                    $this->storeAttachments($promoted, $agencyId, $attachments);

                    return self::RESULT_RECONCILED;
                }
            }

            $communication = Communication::create($common);
            $this->storeAttachments($communication, $agencyId, $attachments);

            CommunicationLink::create([
                'agency_id'        => $agencyId,
                'communication_id' => $communication->id,
                'linkable_type'    => Contact::class,
                'linkable_id'      => $contact->id,
                'link_method'      => CommunicationLink::METHOD_DETERMINISTIC,
                'confidence'       => 100,
                'confirmed_at'     => now(),
            ]);

            $contact->touchLastContacted($communication->occurred_at);

            return self::RESULT_ARCHIVED;
        });
    }

    private function alreadySeen(int $agencyId, string $externalId): bool
    {
        $inArchive = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('external_id', $externalId)
            ->exists();

        if ($inArchive) {
            return true;
        }

        return CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('external_id', $externalId)
            ->exists();
    }

    private function storeAttachments(Communication $communication, int $agencyId, array $attachments): void
    {
        foreach ($attachments as $att) {
            $bytes = (string) ($att['bytes'] ?? '');
            if ($bytes === '') {
                continue;
            }
            $stored = $this->storage->store($agencyId, 'attachment', $bytes);

            CommunicationAttachment::create([
                'agency_id'        => $agencyId,
                'communication_id' => $communication->id,
                'filename'         => $att['filename'] ?? null,
                'mime'             => $att['mime'] ?? null,
                'size_bytes'       => strlen($bytes),
                'content_hash'     => $stored['content_hash'],
                'storage_path'     => $stored['path'],
            ]);
        }
    }
}
