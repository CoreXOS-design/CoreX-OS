<?php

namespace App\Console\Commands;

use App\Models\CommandCenter\CalendarEvent;
use App\Services\CommandCenter\Calendar\Sources\DealCalendarSource;
use Illuminate\Console\Command;

class SyncDealCalendar extends Command
{
    protected $signature = 'deals:sync-calendar {--dry : Show what would be synced without writing}';
    protected $description = 'Sync all active deal milestones to the calendar (catch-up for existing deals)';

    public function handle(): int
    {
        $source = app(DealCalendarSource::class);
        $events = $source->syncAll();
        $dry = (bool) $this->option('dry');

        $this->info("Found {$events->count()} deal calendar events to sync.");

        if ($dry) {
            foreach ($events as $e) {
                $this->line("  [{$e['category']}] {$e['title']} — {$e['event_date']}");
            }
            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        foreach ($events as $payload) {
            $existing = CalendarEvent::withoutGlobalScopes()
                ->where('source_type', $payload['source_type'])
                ->where('source_id', $payload['source_id'])
                ->where('category', $payload['category'])
                ->first();

            if ($existing) {
                $existing->update([
                    'title' => $payload['title'],
                    'event_date' => $payload['event_date'],
                    'user_id' => $payload['user_id'],
                    'agency_id' => $payload['agency_id'],
                    'branch_id' => $payload['branch_id'],
                    'property_id' => $payload['property_id'] ?? null,
                    'metadata' => $payload['metadata'] ?? null,
                ]);
                $updated++;
            } else {
                CalendarEvent::withoutGlobalScopes()->create([
                    'event_type' => $payload['event_type'] ?? 'deal',
                    'category' => $payload['category'],
                    'title' => $payload['title'],
                    'event_date' => $payload['event_date'],
                    'source_type' => $payload['source_type'],
                    'source_id' => $payload['source_id'],
                    'user_id' => $payload['user_id'],
                    'agency_id' => $payload['agency_id'],
                    'branch_id' => $payload['branch_id'],
                    'property_id' => $payload['property_id'] ?? null,
                    'metadata' => $payload['metadata'] ?? null,
                    'status' => 'pending',
                ]);
                $created++;
            }
        }

        $this->info("Done. Created: {$created}, Updated: {$updated}");
        return self::SUCCESS;
    }
}
