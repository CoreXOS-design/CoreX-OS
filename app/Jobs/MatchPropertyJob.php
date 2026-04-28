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

class MatchPropertyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $propertyId) {}

    public function handle(MatchingService $matching): void
    {
        $property = Property::find($this->propertyId);
        if (!$property) return;
        if (!(int) PerformanceSetting::get('matches_enabled', 1)) return;
        if (!$property->agency_id || !$property->price) return;

        $minScore = (int) PerformanceSetting::get('matches_min_score_to_notify', 60);
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
