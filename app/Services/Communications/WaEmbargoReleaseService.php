<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AT-168 Part B — release / recover an EMBARGOED WhatsApp body.
 *
 * The embargo contract: a message captured while agent capture-consent was
 * pending is stored with body_status='embargoed' and the full body kept in the
 * encrypted-at-rest raw, but NEVER displayed (body_text stays null). This
 * service is the release valve — it hydrates the display body from the stored
 * raw (fast, reliable) or, for legacy rows whose raw was redacted before the
 * embargo existed (body_status='consent_pending'), re-fetches the message from
 * the WAHA session store (best-effort, "where retrievable" — GOWS evicts old
 * media, and extension-era chats a WAHA session never held cannot be recovered).
 *
 * Consent-aware: the display body is filled ONLY when the owning agent has
 * opted IN to that contact. For a still-pending / opted-out row we may recover
 * the body INTO the embargo (raw + status), but body_text stays null so nothing
 * is shown before consent — release then happens instantly on opt-in.
 */
class WaEmbargoReleaseService
{
    /** Withheld statuses whose body may still be released/recovered. */
    private const WITHHELD = ['embargoed', 'consent_pending'];

    public function __construct(
        private CommunicationStorageService $storage,
        private WahaSessionClient $session,
        private WaArchiveIngestor $ingestor,
        private AgentCaptureConsentService $consent,
    ) {
    }

    /**
     * Release every withheld message for (agent, contact) — the on-opt-in path.
     * Consent is opted-in by construction here, so bodies become visible.
     *
     * @return array{released:int,recovered:int,failed:int}
     */
    public function releaseForAgentContact(int $agencyId, int $agentUserId, int $contactId): array
    {
        $commIds = CommunicationLink::query()->withoutGlobalScope(AgencyScope::class)
            ->where('linkable_type', Contact::class)
            ->where('linkable_id', $contactId)
            ->pluck('communication_id');

        if ($commIds->isEmpty()) {
            return ['released' => 0, 'recovered' => 0, 'failed' => 0];
        }

        $rows = Communication::query()->withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $commIds)
            ->where('agency_id', $agencyId)
            ->where('owner_user_id', $agentUserId)
            ->whereNull('purged_at')
            ->whereIn('body_status', self::WITHHELD)
            ->get();

        $tally = ['released' => 0, 'recovered' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $outcome = $this->releaseOne($row);
            if (isset($tally[$outcome])) {
                $tally[$outcome]++;
            }
        }

        return $tally;
    }

    /**
     * Release/recover one withheld message.
     *
     * @return string one of: released (body now visible), recovered (body pulled
     *                into the embargo, awaiting opt-in), failed (unrecoverable).
     */
    public function releaseOne(Communication $comm): string
    {
        if (! in_array($comm->body_status, self::WITHHELD, true)) {
            return 'released'; // nothing to do — already visible/purged
        }

        $contactId = (int) CommunicationLink::query()->withoutGlobalScope(AgencyScope::class)
            ->where('communication_id', $comm->id)
            ->where('linkable_type', Contact::class)
            ->value('linkable_id');

        $optedIn = $contactId > 0
            && $this->consent->isCaptureOptedIn((int) $comm->owner_user_id, $contactId);

        // 1) Try the local raw first (embargoed rows carry the full body there).
        [$text, $media] = $this->fromRaw($comm);

        // 2) No local body → best-effort WAHA re-fetch (legacy consent_pending rows
        //    whose raw was redacted before the embargo existed).
        $recovered = false;
        if (($text === null || $text === '') && empty($media)) {
            [$text, $media, $recovered] = $this->fromWaha($comm);
            if (! $recovered) {
                return 'failed';
            }
        }

        if ($optedIn) {
            $this->applyVisibleBody($comm, $text, $media);
            return 'released';
        }

        // Recovered but consent not yet granted → keep it embargoed (body in raw,
        // never displayed). Release will fire instantly on opt-in.
        if ($recovered) {
            $this->reEmbargoRaw($comm, $text, $media);
            return 'recovered';
        }

        // Local raw already held the body and consent isn't granted — leave as-is.
        return 'recovered';
    }

    /** Extract [text, media] from the stored raw payload, or [null, []]. */
    private function fromRaw(Communication $comm): array
    {
        if (! $comm->raw_path) {
            return [null, []];
        }
        $raw = $this->storage->get($comm->raw_path);
        $payload = $raw ? json_decode($raw, true) : null;
        if (! is_array($payload)) {
            return [null, []];
        }
        $text  = isset($payload['text']) && is_string($payload['text']) ? $payload['text'] : null;
        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];

        return [$text, $media];
    }

    /**
     * Best-effort recover the body from the WAHA session store.
     *
     * @return array{0:?string,1:array,2:bool} [text, media, recovered?]
     */
    private function fromWaha(Communication $comm): array
    {
        $device = CommunicationWaDevice::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $comm->agency_id)
            ->where('user_id', $comm->owner_user_id)
            ->whereNotNull('waha_session')
            ->where('active', true)
            ->first();

        $chat = (string) ($comm->wa_chat_id ?: $comm->thread_key);
        if (! $device || $chat === '' || str_starts_with($chat, 'wa:')) {
            // wa_chat_id is the addressable id; a canonical-only key can't address WAHA.
            return [null, [], false];
        }

        try {
            $messages = $this->session->fetchMessages((string) $device->waha_session, $chat);
        } catch (\Throwable $e) {
            Log::warning('AT-168 embargo WAHA recovery fetch failed', [
                'communication_id' => $comm->id, 'error' => $e->getMessage(),
            ]);
            return [null, [], false];
        }

        $needle = (string) $comm->external_id;
        foreach ($messages as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id !== '' && ($id === $needle || str_contains($needle, $id) || str_contains($id, $needle))) {
                $text  = is_string($m['body'] ?? null) ? $m['body'] : ($m['text'] ?? null);
                $media = is_array($m['media'] ?? null) ? [$m['media']] : [];
                return [is_string($text) ? $text : null, $media, true];
            }
        }

        return [null, [], false];
    }

    /** Fill the visible body + materialise media (consent granted). Idempotent. */
    private function applyVisibleBody(Communication $comm, ?string $text, array $media): void
    {
        $hasText = is_string($text) && trim($text) !== '';
        $comm->forceFill([
            'body_text'    => $hasText ? $text : null,
            'body_preview' => $hasText ? Str::limit($text, 160) : null,
            'body_status'  => $hasText ? 'captured' : 'unreadable',
            'text_hash'    => MessageTextHasher::hash(Communication::CHANNEL_WHATSAPP, null, $hasText ? $text : null),
        ])->save();

        if (! empty($media)) {
            $this->ingestor->hydrateMedia($comm->fresh(), $media);
        }
    }

    /** Persist a WAHA-recovered body back INTO the embargo (not displayed). */
    private function reEmbargoRaw(Communication $comm, ?string $text, array $media): void
    {
        $payload = ['text' => $text, 'media' => $media, 'recovered_at' => now()->toIso8601String()];
        $stored  = $this->storage->store((int) $comm->agency_id, 'whatsapp', json_encode($payload) ?: '');
        $comm->forceFill([
            'raw_path'     => $stored['path'],
            'content_hash' => $stored['content_hash'],
            'body_status'  => 'embargoed', // upgrade legacy consent_pending → recoverable embargo
        ])->save();
    }
}
