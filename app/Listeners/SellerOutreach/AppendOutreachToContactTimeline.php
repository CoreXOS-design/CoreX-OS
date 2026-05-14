<?php

declare(strict_types=1);

namespace App\Listeners\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Events\SellerOutreach\PitchClicked;
use App\Events\SellerOutreach\PitchSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appends an entry to the contact's outreach timeline on PitchSent /
 * PitchClicked / OptOutRecorded.
 *
 * Sync. Failure-isolated — a timeline-write failure must not break the
 * parent event flow (compare RecordOptOutOnContact, which re-throws).
 *
 * Writes to contact_outreach_log (Option B per Prompt 03 pre-flight —
 * CoreX has no pre-existing generic activity table to hijack).
 *
 * Per-request idempotency: Laravel's default event auto-discovery scans
 * Listeners/ for `handle()` method type hints and auto-registers them,
 * which combined with the explicit Event::listen() in AppServiceProvider
 * produces TWO registrations per (event, listener). Without dedupe, each
 * event yields 2 timeline rows. The seen-set below catches the second
 * call within the same PHP process — matching the implicit idempotency
 * the existing prospecting cache-invalidator and the audit log's UNIQUE
 * constraint rely on.
 */
final class AppendOutreachToContactTimeline
{
    /** @var array<string, true> Event IDs already processed this request. */
    private static array $seen = [];

    public function handle(PitchSent|PitchClicked|OptOutRecorded $event): void
    {
        if (isset(self::$seen[$event->eventId])) {
            return;
        }
        self::$seen[$event->eventId] = true;

        try {
            [$contactId, $sendId, $kind, $summary, $occurredAt, $actor, $agencyId] = match (true) {
                $event instanceof PitchSent => [
                    $event->send->contact_id,
                    $event->send->id,
                    'sent',
                    sprintf(
                        '%s pitch sent%s',
                        ucfirst($event->send->channel),
                        $event->send->template_id ? ' — template #' . $event->send->template_id : ''
                    ),
                    $event->send->sent_at ?? now(),
                    $event->actorUserId,
                    $event->agencyId,
                ],
                $event instanceof PitchClicked => [
                    $event->send->contact_id,
                    $event->send->id,
                    'clicked',
                    $event->isFirstClick
                        ? 'Seller clicked the pitch link (first click)'
                        : 'Seller clicked the pitch link again',
                    $event->click->clicked_at,
                    null,
                    $event->agencyId,
                ],
                $event instanceof OptOutRecorded => [
                    $event->contact->id,
                    $event->send?->id,
                    'opted_out',
                    'Opt-out recorded — no further pitches will be sent',
                    now(),
                    $event->actorUserId,
                    $event->agencyId,
                ],
            };

            DB::table('contact_outreach_log')->insert([
                'agency_id' => $agencyId,
                'contact_id' => $contactId,
                'send_id' => $sendId,
                'event_kind' => $kind,
                'occurred_at' => $occurredAt,
                'summary' => $summary,
                'actor_user_id' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('AppendOutreachToContactTimeline failed', [
                'event_class' => $event::class,
                'agency_id' => $event->agencyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
