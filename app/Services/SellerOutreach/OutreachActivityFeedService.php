<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\AgentActivityEvent;
use App\Models\ProspectingClaim;
use App\Models\SellerOutreach\SellerOutreachSend;
use Illuminate\Support\Facades\DB;

/**
 * Part 4 — the READER over the agent_activity_events backbone for the unified
 * "Outreach & Canvassing" board (Activity Feed tab).
 *
 * It reads the SAME single activity model every other writer feeds (no parallel
 * store) and SOURCE-TAGS every row into exactly one of three streams that are
 * NEVER blended:
 *
 *   mic_prospect   — canvassing that originated in Market Intelligence prospecting
 *                    (claims + pitches whose property is a matched prospecting listing)
 *   direct_contact — outreach composed straight from a contact / address (a pitch
 *                    whose property is NOT a matched prospecting listing, or an
 *                    address-only send with no property)
 *   comms_tile     — the contact comms-tile quick-send (CommsTileMessageSent)
 *
 * The MIC-vs-direct split for a pitch is resolved from durable facts: the activity
 * row's subject is the SellerOutreachSend, whose property_id is checked against
 * prospecting_listings.matched_property_id (the exact inference the composer uses
 * to attach a MIC claim). Origin is therefore always recoverable — never lost.
 *
 * Returns per-source SUBTOTALS plus a total that is the visible SUM of the parts,
 * so "where was this WhatsApp generated from?" is always answerable.
 */
final class OutreachActivityFeedService
{
    public const SOURCE_MIC        = 'mic_prospect';
    public const SOURCE_DIRECT     = 'direct_contact';
    public const SOURCE_COMMS_TILE = 'comms_tile';

    public const SOURCE_LABELS = [
        self::SOURCE_MIC        => 'MIC prospecting',
        self::SOURCE_DIRECT     => 'Direct contact',
        self::SOURCE_COMMS_TILE => 'Comms tile',
    ];

    /** Send-lifecycle events whose source is resolved via the SellerOutreachSend. */
    private const PITCH_EVENTS = [
        'pitch.sent', 'pitch.clicked', 'outreach_outcome.updated', 'opt_out.recorded',
    ];

    /** Canvassing events that are always MIC-prospecting. */
    private const CLAIM_EVENTS = ['claim.created', 'claim_feedback.recorded'];

    /** The comms-tile quick-send. */
    private const COMMS_TILE_EVENTS = ['comms_tile_message.sent'];

    private const ACTION_LABELS = [
        'pitch.sent'               => 'Pitch sent',
        'pitch.clicked'            => 'Pitch link clicked',
        'outreach_outcome.updated' => 'Outcome updated',
        'opt_out.recorded'         => 'Opted out',
        'claim.created'            => 'Listing claimed',
        'claim_feedback.recorded'  => 'Claim feedback',
        'comms_tile_message.sent'  => 'Comms-tile message',
    ];

    /**
     * @param  array{days?:int,source?:string,user_id?:int,limit?:int}  $filters
     * @return array{rows:array<int,array<string,mixed>>,subtotals:array<string,int>,total:int,days:int,source:?string,truncated:bool}
     */
    public function feed(int $agencyId, array $filters = []): array
    {
        $days   = max(1, (int) ($filters['days'] ?? 90));
        $limit  = max(1, min(1000, (int) ($filters['limit'] ?? 500)));
        $source = in_array($filters['source'] ?? null, array_keys(self::SOURCE_LABELS), true)
            ? $filters['source']
            : null;

        $eventTypes = array_merge(self::PITCH_EVENTS, self::CLAIM_EVENTS, self::COMMS_TILE_EVENTS);

        $query = AgentActivityEvent::query()
            ->where('agency_id', $agencyId)
            ->whereIn('event_type', $eventTypes)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        // Pull one extra to detect truncation.
        $events = $query->limit($limit + 1)->get();
        $truncated = $events->count() > $limit;
        $events = $events->take($limit);

        // ── Batch resolve sends (source) ───────────────────────────────────
        $sendIds = [];
        foreach ($events as $e) {
            $sid = $this->sendIdFor($e);
            if ($sid) {
                $sendIds[] = $sid;
            }
        }
        $sends = collect();
        if ($sendIds) {
            $sends = DB::table('seller_outreach_sends')
                ->whereIn('id', array_values(array_unique($sendIds)))
                ->where('agency_id', $agencyId)
                ->get(['id', 'property_id', 'contact_id', 'channel'])
                ->keyBy('id');
        }

        // MIC property set: any property that is a matched prospecting listing.
        $propertyIds = $sends->pluck('property_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $micProperties = collect();
        if ($propertyIds) {
            $micProperties = DB::table('prospecting_listings')
                ->whereIn('matched_property_id', $propertyIds)
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->pluck('matched_property_id')
                ->map(fn ($v) => (int) $v)
                ->flip(); // value => index, for O(1) has()
        }

        // ── Batch resolve display names ────────────────────────────────────
        $userIds = $events->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $userNames = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')
            : collect();

        $contactIds = [];
        foreach ($events as $e) {
            $cid = $this->contactIdFor($e, $sends);
            if ($cid) {
                $contactIds[] = $cid;
            }
        }
        $contactNames = collect();
        if ($contactIds) {
            $contactNames = DB::table('contacts')
                ->whereIn('id', array_values(array_unique($contactIds)))
                ->where('agency_id', $agencyId)
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($c) => [
                    (int) $c->id => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: 'Contact #' . $c->id,
                ]);
        }

        // ── Build rows + subtotals ─────────────────────────────────────────
        $subtotals = [self::SOURCE_MIC => 0, self::SOURCE_DIRECT => 0, self::SOURCE_COMMS_TILE => 0];
        $rows = [];

        foreach ($events as $e) {
            $rowSource = $this->resolveSource($e, $sends, $micProperties);
            $subtotals[$rowSource]++;

            if ($source !== null && $rowSource !== $source) {
                continue; // filtered out of the visible list, but still counted in its subtotal
            }

            $payload = is_array($e->payload) ? $e->payload : [];
            $contactId = $this->contactIdFor($e, $sends);

            $rows[] = [
                'id'           => (int) $e->id,
                'source'       => $rowSource,
                'source_label' => self::SOURCE_LABELS[$rowSource],
                'action'       => self::ACTION_LABELS[$e->event_type] ?? str_replace(['.', '_'], [' ', ' '], (string) $e->event_type),
                'agent'        => $e->user_id ? ($userNames[$e->user_id] ?? 'Agent #' . $e->user_id) : 'System',
                'who'          => $contactId ? ($contactNames[$contactId] ?? 'Contact #' . $contactId) : $this->fallbackWho($e, $payload),
                'channel'      => $this->channelFor($e, $payload, $sends),
                'outcome'      => $this->outcomeFor($e, $payload),
                'when'         => $e->occurred_at,
            ];
        }

        return [
            'rows'      => $rows,
            'subtotals' => $subtotals,
            'total'     => array_sum($subtotals),
            'days'      => $days,
            'source'    => $source,
            'truncated' => $truncated,
        ];
    }

    /** The SellerOutreachSend id behind a pitch-lifecycle row, or null. */
    private function sendIdFor(AgentActivityEvent $e): ?int
    {
        if ($e->subject_type === SellerOutreachSend::class && $e->subject_id) {
            return (int) $e->subject_id;
        }
        // Opt-out subjects on the Contact but carries the send id in its payload.
        if ($e->event_type === 'opt_out.recorded') {
            $sid = is_array($e->payload) ? ($e->payload['send_id'] ?? null) : null;
            return $sid ? (int) $sid : null;
        }
        return null;
    }

    private function resolveSource(AgentActivityEvent $e, $sends, $micProperties): string
    {
        if (in_array($e->event_type, self::CLAIM_EVENTS, true)) {
            return self::SOURCE_MIC;
        }
        if (in_array($e->event_type, self::COMMS_TILE_EVENTS, true)) {
            return self::SOURCE_COMMS_TILE;
        }

        // Pitch lifecycle: MIC iff the send's property is a matched prospecting listing.
        $sendId = $this->sendIdFor($e);
        $send = $sendId ? $sends->get($sendId) : null;
        $propertyId = $send->property_id ?? null;

        return ($propertyId && $micProperties->has((int) $propertyId))
            ? self::SOURCE_MIC
            : self::SOURCE_DIRECT;
    }

    private function contactIdFor(AgentActivityEvent $e, $sends): ?int
    {
        $payload = is_array($e->payload) ? $e->payload : [];

        if (! empty($payload['contact_id'])) {
            return (int) $payload['contact_id'];
        }
        // Comms-tile + opt-out subject the Contact directly.
        if ($e->subject_type === \App\Models\Contact::class && $e->subject_id) {
            return (int) $e->subject_id;
        }
        // Fall back to the send's contact.
        $sendId = $this->sendIdFor($e);
        $send = $sendId ? $sends->get($sendId) : null;
        return $send && $send->contact_id ? (int) $send->contact_id : null;
    }

    private function channelFor(AgentActivityEvent $e, array $payload, $sends): string
    {
        if (! empty($payload['channel'])) {
            return (string) $payload['channel'];
        }
        $sendId = $this->sendIdFor($e);
        $send = $sendId ? $sends->get($sendId) : null;
        return $send && $send->channel ? (string) $send->channel : '';
    }

    private function outcomeFor(AgentActivityEvent $e, array $payload): string
    {
        return match ($e->event_type) {
            'outreach_outcome.updated' => ucfirst(str_replace('_', ' ', (string) ($payload['new_outcome'] ?? ''))),
            'opt_out.recorded'         => (string) ($payload['reason'] ?? 'Opted out'),
            'claim_feedback.recorded'  => ProspectingClaim::humanStatus(
                (string) ($payload['new_status'] ?? $payload['status'] ?? '')
            ),
            'claim.created'            => 'Claimed',
            'pitch.clicked'            => 'Clicked',
            'pitch.sent'               => 'Sent',
            default                    => '',
        };
    }

    /** Display fallback when there's no resolvable contact (e.g. a claim row). */
    private function fallbackWho(AgentActivityEvent $e, array $payload): string
    {
        if (! empty($payload['listing_id'])) {
            return 'Listing #' . (int) $payload['listing_id'];
        }
        return '—';
    }
}
