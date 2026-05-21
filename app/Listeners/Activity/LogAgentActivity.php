<?php

declare(strict_types=1);

namespace App\Listeners\Activity;

use App\Events\AbstractDomainEvent;
use App\Models\AgentActivityEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes every MIC domain event to agent_activity_events.
 *
 * Subscribes per spec §14.6 to ALL events in §14.1-§14.5 (registered via
 * Event::listen() in AppServiceProvider). Synchronous insert — fast, and
 * deterministic ordering matters for debugging.
 *
 * TODO (Phase 5): move to queue (ShouldQueue) once volumes warrant.
 *
 * ROBUSTNESS: a logging failure must NEVER break the underlying business
 * operation. Every insert is wrapped in try/catch + Log::warning(). If
 * activity logging breaks, the business write still commits — we lose
 * the activity row, but never the user's actual save.
 */
final class LogAgentActivity
{
    public function handle(AbstractDomainEvent $event): void
    {
        try {
            $subject = $event->subject();
            $payload = $this->buildPayload($event);

            AgentActivityEvent::create([
                'agency_id'    => $event->agencyId(),
                'user_id'      => $event->actorUserId() ?? Auth::id(),
                'event_type'   => $this->eventTypeKey($event),
                'subject_type' => $subject[0] ?? null,
                'subject_id'   => $subject[1] ?? null,
                'payload'      => $payload,
                'occurred_at'  => $event->occurredAt,
                'created_at'   => now(),
            ]);
        } catch (Throwable $e) {
            // Activity logging failure does not break the underlying op.
            Log::warning('LogAgentActivity insert failed', [
                'event'   => $event::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Derive the canonical event_type slug from the event class name.
     *
     *   TrackedPropertyAddressAdded   → tracked_property_address.added
     *   WhatsAppMessageSent            → whats_app_message.sent      (then
     *                                    cleaned to whatsapp_message.sent)
     *   ClaimFlaggedAsStale            → claim.flagged_as_stale
     *   MarketReportSpotCheckFlagged   → market_report.spot_check_flagged
     *
     * Pattern: snake_case the basename, then split on the LAST underscore
     * so "<thing>.<verb>" reads naturally. Single-word events stay as one
     * segment (e.g. "claim_created" → "claim.created").
     */
    private function eventTypeKey(AbstractDomainEvent $event): string
    {
        $basename = class_basename($event);
        $snake = Str::snake($basename);
        $snake = str_replace('whats_app', 'whatsapp', $snake);
        $snake = str_replace('a_i_', 'ai_', $snake);

        $lastUnderscore = strrpos($snake, '_');
        if ($lastUnderscore === false) {
            return $snake;
        }
        $thing = substr($snake, 0, $lastUnderscore);
        $verb  = substr($snake, $lastUnderscore + 1);
        return $thing . '.' . $verb;
    }

    /**
     * Merge the event's context() block with its eventId + traceId so the
     * activity row carries full trace info for cross-event correlation.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(AbstractDomainEvent $event): array
    {
        return array_merge(
            [
                'event_class' => $event::class,
                'event_id'    => $event->eventId,
                'trace_id'    => $event->traceId,
            ],
            $event->context(),
        );
    }
}
