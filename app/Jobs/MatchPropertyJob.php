<?php

namespace App\Jobs;

use App\Models\ContactMatch;
use App\Models\ContactMatchNotification;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Notifications\NewPropertyMatchNotification;
use App\Services\Matching\MatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Core Matches: notify agents when a property matches a contact's wishlist.
 *
 * Runs on the dedicated `matching` queue (not `default`) so a bulk-import
 * "herd" of match jobs can never starve time-sensitive P24 sync/push/confirm
 * work on `default`. The corex worker must drain `default,matching` (in that
 * priority order) — default is always processed first, matching only when
 * default is idle. Shared with MatchPropertyProspectingJob.
 */
class MatchPropertyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'matching';

    public function __construct(public int $propertyId)
    {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(MatchingService $matching): void
    {
        $property = Property::find($this->propertyId);
        if (!$property) return;
        if (!$property->agency_id || !$property->price) return;
        // Per-agency read (multi-tenancy #7): this runs on the queue with NO auth,
        // so the agency must be passed explicitly — a bare get() would read the
        // global default and honour the wrong agency's toggle.
        if (!(int) PerformanceSetting::get('matches_enabled', 1, $property->agency_id)) return;

        $minScore = (int) PerformanceSetting::get('matches_min_score_to_notify', 60, $property->agency_id);
        $candidates = $matching->candidatesForProperty($property);

        foreach ($candidates as $match) {
            /** @var ContactMatch $match */
            $score = $matching->score($property, $match);
            if ($score < $minScore) continue;

            // Dedup: skip if we've already notified for this (match, property)
            $exists = ContactMatchNotification::where('contact_match_id', $match->id)
                ->where('property_id', $property->id)
                ->exists();
            if ($exists) continue;

            $agent = $match->createdBy;
            if (!$agent) continue;

            try {
                $agent->notify(new NewPropertyMatchNotification($match, $property, $score));

                ContactMatchNotification::create([
                    // MUST be set explicitly: this runs on the queue with no
                    // Auth::user(), so BelongsToAgency can't infer agency_id and
                    // the NOT NULL insert silently failed — which meant the dedup
                    // row was NEVER written and every re-save of a property
                    // re-notified every match (the match-email spam). Take the
                    // owning agency straight off the property.
                    'agency_id'        => $property->agency_id,
                    'contact_match_id' => $match->id,
                    'property_id'      => $property->id,
                    'score'            => $score,
                    'notified_user_id' => $agent->id,
                    'created_at'       => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("MatchPropertyJob notify failed match={$match->id} prop={$property->id}: {$e->getMessage()}");
            }
        }
    }
}
