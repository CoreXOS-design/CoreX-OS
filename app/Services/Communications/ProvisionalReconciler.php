<?php

namespace App\Services\Communications;

use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Carbon;

/**
 * Reconciles an ingested OUTBOUND message against an existing PROVISIONAL row
 * (AT-59). When an agent clicked "send" we recorded a provisional communication
 * immediately; when the real message is later ingested from the Sent folder / WA
 * capture, we PROMOTE that provisional row in place instead of inserting a
 * duplicate — so a single send is always exactly one archive row.
 *
 * Match strategy (priority order):
 *   1. Exact text_hash — the agent did not edit the message before sending.
 *   2. Time-window fallback — same agency/contact/channel/outbound, occurred_at
 *      within ± the agency's reconcile window, nearest in time wins. Covers the
 *      edited-before-send case where the hashes differ.
 *
 * No match → returns null and the caller inserts a fresh confirmed row.
 *
 * Runs in job context (no authenticated user) so every query drops AgencyScope
 * and filters agency_id explicitly, mirroring the ingestors' dedup path.
 */
class ProvisionalReconciler
{
    /**
     * @param Contact     $contact the resolved counterpart contact
     * @param string      $channel Communication::CHANNEL_EMAIL|CHANNEL_WHATSAPP
     * @param array       $promote real-message fields to write onto the promoted
     *                    row (external_id, thread_key, from_identifier,
     *                    participant_identifiers, occurred_at, captured_at,
     *                    subject, body_text, body_preview, raw_path, content_hash,
     *                    text_hash, has_attachments, source_ref)
     * @param Agency|null $agency  for the configurable reconcile window
     *
     * @return Communication|null the promoted row, or null if nothing matched
     */
    public function reconcileOutbound(Contact $contact, string $channel, array $promote, ?Agency $agency = null): ?Communication
    {
        $agencyId   = (int) $contact->agency_id;
        $occurredAt = $this->asCarbon($promote['occurred_at'] ?? null);
        $textHash   = (string) ($promote['text_hash'] ?? '');

        // communication_ids provisionally linked to this contact.
        $linkedIds = CommunicationLink::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('linkable_type', Contact::class)
            ->where('linkable_id', $contact->id)
            ->whereNull('deleted_at')
            ->pluck('communication_id');

        if ($linkedIds->isEmpty()) {
            return null;
        }

        $base = fn () => Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('channel', $channel)
            ->where('direction', Communication::DIRECTION_OUTBOUND)
            ->whereNotNull('provisional_at')
            ->whereNull('purged_at')
            ->whereIn('id', $linkedIds);

        // 1. Exact text-hash match — most recent first.
        $hit = $textHash !== ''
            ? $base()->where('text_hash', $textHash)->orderByDesc('occurred_at')->first()
            : null;

        // 2. Time-window fallback — nearest in time within the agency window.
        if (! $hit) {
            $window = $agency ? $agency->reconcileWindowMinutes()
                : max(1, (int) config('communications.reconcile_window_minutes', 2880));

            $lo = $occurredAt->copy()->subMinutes($window);
            $hi = $occurredAt->copy()->addMinutes($window);

            $hit = $base()
                ->whereBetween('occurred_at', [$lo, $hi])
                ->get()
                ->sortBy(fn (Communication $c) => abs($c->occurred_at->diffInSeconds($occurredAt)))
                ->first();
        }

        if (! $hit) {
            return null;
        }

        // Promote in place: overwrite with the real message and clear provisional.
        $hit->fill([
            'external_id'             => $promote['external_id'] ?? $hit->external_id,
            'thread_key'              => $promote['thread_key'] ?? $hit->thread_key,
            'from_identifier'         => $promote['from_identifier'] ?? $hit->from_identifier,
            'participant_identifiers' => $promote['participant_identifiers'] ?? $hit->participant_identifiers,
            'occurred_at'             => $occurredAt,
            'captured_at'             => $promote['captured_at'] ?? now(),
            'subject'                 => $promote['subject'] ?? $hit->subject,
            'body_text'               => $promote['body_text'] ?? $hit->body_text,
            'body_preview'            => $promote['body_preview'] ?? $hit->body_preview,
            'raw_path'                => $promote['raw_path'] ?? $hit->raw_path,
            'content_hash'            => $promote['content_hash'] ?? $hit->content_hash,
            'text_hash'               => $promote['text_hash'] ?? $hit->text_hash,
            'has_attachments'         => (bool) ($promote['has_attachments'] ?? $hit->has_attachments),
            'source_ref'              => $promote['source_ref'] ?? $hit->source_ref,
            'provisional_at'          => null,
        ]);
        $hit->save();

        // The link is now a confirmed archive fact.
        CommunicationLink::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('communication_id', $hit->id)
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => now()]);

        $contact->touchLastContacted($occurredAt);

        return $hit;
    }

    private function asCarbon($value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        try {
            return $value !== null && $value !== '' ? Carbon::parse((string) $value) : now();
        } catch (\Throwable $e) {
            return now();
        }
    }
}
