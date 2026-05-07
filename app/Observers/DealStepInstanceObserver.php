<?php

namespace App\Observers;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\DealV2\DealStepInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DealStepInstanceObserver
{
    /**
     * New step created with due_date → create calendar event immediately.
     */
    public function created(DealStepInstance $step): void
    {
        if ($step->due_date) {
            $this->syncStepEvent($step);
        }
    }

    /**
     * Step updated: date change → update event; completion → auto-complete event.
     */
    public function updated(DealStepInstance $step): void
    {
        if ($step->isDirty('due_date') && $step->due_date) {
            $this->syncStepEvent($step);
        }

        // Step completed → auto-complete the calendar event
        if ($step->isDirty('status') && in_array($step->status, ['completed', 'skipped'])) {
            CalendarEvent::withoutGlobalScopes()
                ->where('source_type', DealStepInstance::class)
                ->where('source_id', $step->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);
        }
    }

    private function syncStepEvent(DealStepInstance $step): void
    {
        try {
            $deal = $step->deal;
            if (!$deal) return;

            $branch = $deal->branch;
            $property = $deal->property;

            $propertyLabel = $property
                ? ($property->address ?: $property->title ?: "Property #{$property->id}")
                : '';

            CalendarEvent::withoutGlobalScopes()->updateOrCreate(
                [
                    'source_type' => DealStepInstance::class,
                    'source_id' => $step->id,
                    'category' => 'deal_step_deadline',
                ],
                [
                    'event_type' => 'deal',
                    'title' => ($step->name ?? 'Step') . ' Due — ' . ($deal->reference ?? 'Deal') . ($propertyLabel ? ' — ' . $propertyLabel : ''),
                    'event_date' => $step->due_date,
                    'user_id' => $deal->selling_agent_id ?? $deal->listing_agent_id,
                    'agency_id' => $branch?->agency_id,
                    'branch_id' => $deal->branch_id,
                    'property_id' => $deal->property_id,
                    'status' => 'pending',
                    'metadata' => [
                        'deal_id' => $deal->id,
                        'deal_ref' => $deal->reference,
                        'step_name' => $step->name,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Deal step calendar sync failed', ['step_id' => $step->id, 'error' => $e->getMessage()]);
        }
    }
}
