<?php

declare(strict_types=1);

namespace App\Listeners\TitleDeeds;

use App\Events\TitleDeeds\TitleDeedCollected;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\TitleDeeds\TitleDeedNotifier;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/title-deed-register.md §8.4 — after a handover: the responsible agent is told through the notification
 * dispatcher (`title_deed.collected`) and EVERY owner is emailed who collected, when, and how it was verified.
 *
 * Synchronous (domain events carry readonly state) and failure-isolated: the handover is already committed, so nothing here
 * may undo it or make the staff member's screen fail.
 */
class NotifyOnTitleDeedCollected
{
    public function handle(TitleDeedCollected $event): void
    {
        $deed = TitleDeed::withoutGlobalScopes()->withTrashed()->find($event->titleDeedId);
        $receipt = TitleDeedReceipt::withoutGlobalScopes()->find($event->receiptId);
        if (! $deed || ! $receipt) {
            return;
        }

        try {
            $agent = User::withoutGlobalScopes()->find($deed->agent_id);
            if ($agent) {
                app(NotificationDispatcher::class)->fire($agent, 'title_deed.collected', $deed, [
                    'title' => 'Title deed collected - ' . $deed->property_display,
                    'body' => $receipt->collector_name . ' collected it (' . $receipt->methodLabel() . ').',
                    'action_url' => route('title-deeds.show', $deed->id),
                    'severity' => 'info',
                    // A discrete event: each collection is a new fact.
                    'threshold_hit_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Title deed: the agent could not be notified of a collection', ['title_deed_id' => $deed->id, 'error' => $e->getMessage()]);
        }

        try {
            app(TitleDeedNotifier::class)->sendCollectedNotices($deed, $receipt);
        } catch (\Throwable $e) {
            Log::warning('Title deed: the owners could not be emailed after a collection', ['title_deed_id' => $deed->id, 'error' => $e->getMessage()]);
        }
    }
}
