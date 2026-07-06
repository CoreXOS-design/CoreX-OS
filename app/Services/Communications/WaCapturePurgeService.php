<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\WaCapturePurgeEvent;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\Log;

/**
 * AT-183 — retroactive purge of an agent↔contact WhatsApp pairing on capture OPT-OUT.
 *
 * When an agent declares a per-contact opt-out ("this pairing is personal — POPIA exclusion"),
 * the messages already archived for that pairing must not linger. This GENUINELY removes the
 * body content (the sanctioned exception to no-hard-delete, for personal-data minimisation) of
 * EVERY WhatsApp message for that (agency, agent, contact) — both already-captured and still-
 * embargoed rows (the daily embargo purge only reaches `embargoed` rows; a captured body like
 * the self-link-default case is never touched by it, which is exactly the live defect).
 *
 * It mirrors the embargo purge's content-removal (body/preview/hashes/raw/transcript nulled,
 * raw bytes + attachment bytes deleted dedup-safe) and the release service's pairing scope
 * (Contact link → agency + owner_user_id). The FICA envelope (identity, timestamp, thread,
 * links) is retained; the row is never deleted; body_status becomes 'consent_revoked' and
 * purged_at is stamped. Every purge writes an immutable {@see WaCapturePurgeEvent} (count, no
 * content) so compliance can show the purge happened.
 */
class WaCapturePurgeService
{
    /** body_status set on a message whose content was purged by an opt-out. */
    public const STATUS_CONSENT_REVOKED = 'consent_revoked';

    /** Communication.purged_reason code for an opt-out purge. */
    public const REASON_CODE = 'capture_opt_out';

    public function __construct(private CommunicationStorageService $storage)
    {
    }

    /**
     * Purge every WhatsApp message for (agency, agent, contact) and record the audit event.
     *
     * @param  string|null $declaration the opt-out reason declaration (NOT message content)
     * @return int the number of messages whose body content was purged
     */
    public function purgeForAgentContact(
        int $agencyId,
        int $agentUserId,
        int $contactId,
        ?string $declaration = null,
        ?int $actorUserId = null,
    ): int {
        $commIds = CommunicationLink::query()->withoutGlobalScope(AgencyScope::class)
            ->where('linkable_type', Contact::class)
            ->where('linkable_id', $contactId)
            ->pluck('communication_id');

        $count = 0;

        if ($commIds->isNotEmpty()) {
            $rows = Communication::query()->withoutGlobalScope(AgencyScope::class)
                ->whereIn('id', $commIds)
                ->where('agency_id', $agencyId)
                ->where('owner_user_id', $agentUserId)
                ->where('channel', Communication::CHANNEL_WHATSAPP)
                ->whereNull('purged_at')
                ->get();

            foreach ($rows as $comm) {
                $this->purgeOne($comm);
                $count++;
            }
        }

        // Always record the episode — even a 0-count opt-out is auditable evidence that the
        // agent declared the exclusion and nothing was (or remained) archived.
        WaCapturePurgeEvent::create([
            'agency_id'     => $agencyId,
            'agent_user_id' => $agentUserId,
            'contact_id'    => $contactId,
            'actor_user_id' => $actorUserId,
            'reason'        => $declaration,
            'message_count' => $count,
            'purged_at'     => now(),
        ]);

        Log::info('AT-183 WA capture opt-out purge', [
            'agency_id' => $agencyId, 'agent_user_id' => $agentUserId,
            'contact_id' => $contactId, 'message_count' => $count,
        ]);

        return $count;
    }

    /** Remove one message's body content + media bytes; retain the FICA envelope. */
    private function purgeOne(Communication $comm): void
    {
        // 1) Message body content FIRST — the critical personal data. Nulling it is the
        // POPIA-essential step, so it happens before the best-effort media pass; a media
        // hiccup must never leave the body un-purged. Envelope retained.
        $rawPath = $comm->raw_path;
        $comm->forceFill([
            'body_text'          => null,
            'body_preview'       => null,
            'body_status'        => self::STATUS_CONSENT_REVOKED,
            'text_hash'          => null,
            'raw_path'           => null,
            'content_hash'       => null,
            'transcript_text'    => null,
            'transcript_preview' => null,
            'transcript_status'  => null,
            'purged_at'          => now(),
            'purged_reason'      => self::REASON_CODE,
        ])->save();

        // Raw bytes: only if no other row still references them (content-addressed dedup safety).
        if ($rawPath) {
            $stillReferenced = Communication::query()->withoutGlobalScope(AgencyScope::class)
                ->where('raw_path', $rawPath)
                ->exists();
            if (! $stillReferenced) {
                $this->storage->delete($rawPath);
            }
        }

        // 2) Media bytes + descriptors (best-effort). Query scope-free — the purge runs in a
        // queued/CLI context with no auth agency, so the AgencyScope-bound relation returns
        // nothing. content_hash is NOT NULL on this table, so it is left intact: the actual
        // bytes are deleted, and a one-way digest is not recoverable content.
        $attachments = CommunicationAttachment::query()->withoutGlobalScope(AgencyScope::class)
            ->where('communication_id', $comm->id)
            ->get();
        foreach ($attachments as $att) {
            /** @var CommunicationAttachment $att */
            $path = $att->storage_path;
            if ($path) {
                $stillReferenced = CommunicationAttachment::query()->withoutGlobalScope(AgencyScope::class)
                    ->where('storage_path', $path)
                    ->where('id', '!=', $att->id)
                    ->exists();
                if (! $stillReferenced) {
                    $this->storage->delete($path);
                }
            }
            $att->forceFill([
                'storage_path' => null,
                'media_status' => 'purged',
            ])->save();
        }
    }
}
