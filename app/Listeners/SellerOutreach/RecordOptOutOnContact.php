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
            $event->contact->update([
                'messaging_opt_out_at' => now(),
                'messaging_opt_out_reason' => $event->reason,
                'messaging_opt_out_recorded_by_user_id' => $event->actorUserId,
            ]);
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
