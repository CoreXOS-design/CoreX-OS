<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Contracts\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wildcard audit listener — subscribes to every AbstractDomainEvent subclass
 * and writes one row to domain_event_log.
 *
 * Spec: .ai/specs/corex-domain-events-spec.md Section 6, E6.
 *
 * Sync listener. Should complete in ~5ms per event. If this drifts past
 * 20ms it moves to queued per spec E10 performance budget.
 *
 * Failure handling: catches every Throwable and logs to laravel.log rather
 * than letting an audit failure cascade into a user-facing error. The audit
 * log is best-effort durability, NOT a transactional dependency of the
 * user's request.
 *
 * Bypass for emergencies: setting config('corex.domain_events.audit_enabled')
 * to false disables this listener at runtime. Default: true.
 */
class RecordDomainEvent
{
    public function handle(DomainEvent $event): void
    {
        if (!config('corex.domain_events.audit_enabled', true)) {
            return;
        }

        try {
            $subject = $event->subject();

            // event_id, traceId, and occurredAt are concrete-class properties
            // (defined on AbstractDomainEvent). Read via property access since
            // the interface deliberately doesn't promise them — keeps the
            // contract narrow and lets specialised event classes (e.g. external
            // system events) opt out of the framework metadata if ever needed.
            DB::table('domain_event_log')->insert([
                'event_id'         => $event->eventId,
                'trace_id'         => $event->traceId,
                'event_name'       => $event->eventName(),
                'agency_id'        => $event->agencyId(),
                'actor_user_id'    => $event->actorUserId(),
                'subject_type'     => $subject[0] ?? null,
                'subject_id'       => $subject[1] ?? null,
                'payload_snapshot' => json_encode($event->payloadSnapshot()),
                'context'          => json_encode($event->context()),
                'occurred_at'      => $event->occurredAt->format('Y-m-d H:i:s.u'),
                'created_at'       => now(),
            ]);
        } catch (Throwable $e) {
            // Audit must not break user-facing flows. Log and move on.
            Log::error('Domain event audit write failed', [
                'event_class' => $event->eventName(),
                'event_id'    => $event->eventId,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
        }
    }
}
