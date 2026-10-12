<?php

declare(strict_types=1);

namespace App\Listeners\TitleDeeds;

use App\Events\TitleDeeds\TitleDeedCollected;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
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

        // Told: the responsible agent ALWAYS; the deal's further selling agents; the deal's listing agent when the agency says so
        // (setting, default on). Each person once, whatever roles they hold.
        try {
            $ids = collect([$deed->agent_id])->merge((array) $deed->additional_agent_ids);
            if ($deed->listing_agent_id && TitleDeedSetting::notifyListingAgentFor($deed->agency_id)) {
                $ids->push($deed->listing_agent_id);
            }
            $ids = $ids->filter()->map(fn ($i) => (int) $i)->unique()->values();

            foreach ($ids as $id) {
                $agent = User::withoutGlobalScopes()->where('agency_id', $deed->agency_id)->find($id);
                if (! $agent) {
                    continue;
                }
                $role = $id === (int) $deed->agent_id ? '' : ($id === (int) $deed->listing_agent_id ? ' (you are the listing agent)' : ' (you are on the deal)');
                try {
                    app(NotificationDispatcher::class)->fire($agent, 'title_deed.collected', $deed, [
                        'title' => 'Title deed collected - ' . $deed->property_display,
                        'body' => $receipt->collector_name . ' collected it (' . $receipt->methodLabel() . ')' . $role . '.',
                        'action_url' => route('title-deeds.show', $deed->id),
                        'severity' => 'info',
                        // A discrete event: each collection is a new fact.
                        'threshold_hit_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Title deed: an agent could not be notified of a collection', ['title_deed_id' => $deed->id, 'user_id' => $id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Title deed: the agents could not be notified of a collection', ['title_deed_id' => $deed->id, 'error' => $e->getMessage()]);
        }

        try {
            app(TitleDeedNotifier::class)->sendCollectedNotices($deed, $receipt);
        } catch (\Throwable $e) {
            Log::warning('Title deed: the owners could not be emailed after a collection', ['title_deed_id' => $deed->id, 'error' => $e->getMessage()]);
        }
    }
}
