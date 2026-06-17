<?php

declare(strict_types=1);

namespace App\Listeners\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sets messaging_opt_out_at on the contact when OptOutRecorded fires.
 *
 * Sync. **Re-throws on failure** (unlike most listeners in this module).
 * Opt-out is compliance-critical — POPIA exposure if a recorded opt-out is
 * silently lost. The agent's action MUST succeed or surface to them.
 *
 * Per-request idempotency: see AppendOutreachToContactTimeline for the full
 * explanation — Laravel auto-discovery + AppServiceProvider's explicit
 * Event::listen() register this listener twice per event. We dedupe by
 * event_id within the process so messaging_opt_out_at is set exactly once.
 */
final class RecordOptOutOnContact
{
    /** @var array<string, true> */
    private static array $seen = [];

    public function handle(OptOutRecorded $event): void
    {
        if (isset(self::$seen[$event->eventId])) {
            return;
        }
        self::$seen[$event->eventId] = true;

        try {
            // AT-49 convergence: both the agent-marked opt-out and the
            // self-service link reach here, so this is the ONE place that runs
            // the full "suppressed everywhere" path — consent revoke + opt-out
            // triplet (+ source) + channel booleans + identifier suppression.
            app(\App\Services\SellerOutreach\MarketingConsentService::class)->optOutContact(
                contact:     $event->contact,
                reason:      $event->reason,
                source:      $event->source,
                actorUserId: $event->actorUserId,
                send:        $event->send,
                blockAll:    $event->blockAll,
            );
        } catch (Throwable $e) {
            Log::error('RecordOptOutOnContact failed', [
                'contact_id' => $event->contact->id,
                'agency_id' => $event->agencyId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
