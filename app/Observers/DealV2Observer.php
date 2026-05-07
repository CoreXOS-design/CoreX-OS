<?php

namespace App\Observers;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\DealV2\DealV2;
use Illuminate\Support\Facades\Log;

class DealV2Observer
{
    /**
     * On deal key date changes: re-sync calendar events via reconcile for this deal.
     */
    public function saved(DealV2 $deal): void
    {
        $dirty = $deal->getDirty();
        $dateFields = ['offer_date', 'expected_registration', 'actual_registration'];

        if (!empty(array_intersect(array_keys($dirty), $dateFields)) || $deal->wasRecentlyCreated) {
            $this->syncDealCalendarEvents($deal);
        }
    }

    /**
     * On deal cancellation/deletion: dismiss all related calendar events.
     */
    public function updated(DealV2 $deal): void
    {
        if ($deal->isDirty('status') && in_array($deal->status, ['cancelled', 'on_hold'])) {
            CalendarEvent::withoutGlobalScopes()
                ->where('source_type', DealV2::class)
                ->where('source_id', $deal->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->update(['status' => 'dismissed', 'updated_at' => now()]);

            // Also dismiss step events
            $stepIds = $deal->stepInstances()->pluck('id')->toArray();
            if (!empty($stepIds)) {
                CalendarEvent::withoutGlobalScopes()
                    ->where('source_type', \App\Models\DealV2\DealStepInstance::class)
                    ->whereIn('source_id', $stepIds)
                    ->whereIn('status', ['pending', 'overdue'])
                    ->update(['status' => 'dismissed', 'updated_at' => now()]);
            }
        }
    }

    private function syncDealCalendarEvents(DealV2 $deal): void
    {
        try {
            $source = app(\App\Services\CommandCenter\Calendar\Sources\DealCalendarSource::class);
            $events = $source->syncAll();

            // Filter to just this deal's events
            $dealEvents = $events->filter(fn($e) => ($e['metadata']['deal_id'] ?? null) == $deal->id);

            foreach ($dealEvents as $payload) {
                CalendarEvent::withoutGlobalScopes()->updateOrCreate(
                    [
                        'source_type' => $payload['source_type'],
                        'source_id' => $payload['source_id'],
                        'category' => $payload['category'],
                    ],
                    [
                        'event_type' => $payload['event_type'] ?? 'deal',
                        'title' => $payload['title'],
                        'event_date' => $payload['event_date'],
                        'user_id' => $payload['user_id'],
                        'agency_id' => $payload['agency_id'],
                        'branch_id' => $payload['branch_id'],
                        'property_id' => $payload['property_id'] ?? null,
                        'metadata' => $payload['metadata'] ?? null,
                        'status' => 'pending',
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Deal calendar sync failed', ['deal_id' => $deal->id, 'error' => $e->getMessage()]);
        }
    }
}
