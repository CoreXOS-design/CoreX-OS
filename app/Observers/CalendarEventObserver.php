<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CommandCenter\CalendarEvent;
use App\Services\Activity\ProvisionalPointService;
use Illuminate\Support\Facades\Log;

/**
 * Module 6 (M6.3) — observe-only points hook on CalendarEvent lifecycle.
 *
 * SAFETY GUARANTEE: point-writing must NEVER block, delay, or break a
 * calendar action. Every invocation of ProvisionalPointService below is
 * wrapped in try { ... } catch (\Throwable $e) { Log::warning(...); } —
 * the exception is logged and SWALLOWED (never re-thrown). If the points
 * layer crashes, the agent's calendar save still completes. This is
 * observe-only infrastructure; the calendar is the source of truth.
 */
final class CalendarEventObserver
{
    /**
     * On every new CalendarEvent, attempt a provisional credit. The
     * service handles mapping resolution, anti-gaming gates, and
     * idempotency itself — this observer's job is solely to invoke it
     * and to guarantee the calendar save isn't poisoned on failure.
     */
    public function created(CalendarEvent $event): void
    {
        try {
            app(ProvisionalPointService::class)->credit($event);
        } catch (\Throwable $e) {
            Log::warning('M6.3 observer caught exception, calendar action proceeds', [
                'event_id' => $event->id ?? null,
                'method'   => __FUNCTION__,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * On status flip to cancelled/dismissed, revoke any provisional row
     * for this event. All other updates are no-ops at the points layer
     * (M6.4 owns the confirm flow when feedback arrives).
     */
    public function updated(CalendarEvent $event): void
    {
        try {
            if (! $event->wasChanged('status')) {
                return;
            }
            if (! in_array($event->status, ['cancelled', 'dismissed'], true)) {
                return;
            }
            app(ProvisionalPointService::class)->revoke($event, 'event_cancelled');
        } catch (\Throwable $e) {
            Log::warning('M6.3 observer caught exception, calendar action proceeds', [
                'event_id' => $event->id ?? null,
                'method'   => __FUNCTION__,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fired by Eloquent on soft-delete (the CalendarEvent uses SoftDeletes
     * — deleted_at is set, the row stays). We revoke the provisional row
     * but never hard-delete it: the audit trail must survive.
     */
    public function deleted(CalendarEvent $event): void
    {
        try {
            app(ProvisionalPointService::class)->revoke($event, 'event_soft_deleted');
        } catch (\Throwable $e) {
            Log::warning('M6.3 observer caught exception, calendar action proceeds', [
                'event_id' => $event->id ?? null,
                'method'   => __FUNCTION__,
                'message'  => $e->getMessage(),
            ]);
        }
    }
}
