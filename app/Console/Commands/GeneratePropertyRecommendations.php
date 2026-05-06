<?php

namespace App\Console\Commands;

use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeneratePropertyRecommendations extends Command
{
    protected $signature = 'properties:generate-recommendations {--property= : Specific property ID}';
    protected $description = 'Generate auto-derived recommendations for properties based on feedback patterns';

    public function handle(): int
    {
        $query = Property::withoutGlobalScopes()->whereNotNull('published_at')->whereNull('deleted_at');
        if ($id = $this->option('property')) {
            $query->where('id', (int) $id);
        }

        $properties = $query->get(['id', 'agency_id', 'published_at', 'price']);
        $this->info("Scanning {$properties->count()} properties...");
        $generated = 0;

        foreach ($properties as $property) {
            $recs = $this->analyseProperty($property);
            foreach ($recs as $rec) {
                // Don't duplicate existing active recommendations
                $exists = DB::table('property_recommendations')
                    ->where('property_id', $property->id)
                    ->where('recommendation_code', $rec['code'])
                    ->whereNull('dismissed_at')
                    ->whereNull('actioned_at')
                    ->exists();
                if ($exists) continue;

                DB::table('property_recommendations')->insert([
                    'property_id' => $property->id,
                    'agency_id' => $property->agency_id,
                    'recommendation_code' => $rec['code'],
                    'title' => $rec['title'],
                    'reasoning' => $rec['reasoning'],
                    'suggested_action' => $rec['action'],
                    'seller_facing_title' => $rec['seller_title'] ?? null,
                    'seller_facing_reasoning' => $rec['seller_reasoning'] ?? null,
                    'generated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $generated++;
            }
        }

        $this->info("Generated {$generated} new recommendations.");
        return 0;
    }

    private function analyseProperty(Property $property): array
    {
        $recs = [];
        $daysOnMarket = $property->published_at ? (int) $property->published_at->diffInDays(now()) : 0;

        // Feedback analysis
        $feedbackCount = CalendarEventFeedback::where('property_id', $property->id)->whereNotNull('captured_at')->count();
        $viewingCount = CalendarEventFeedback::where('property_id', $property->id)->whereNotNull('captured_at')->distinct('calendar_event_id')->count('calendar_event_id');

        // Long days on market
        if ($daysOnMarket > 60) {
            $recs[] = [
                'code' => 'long_dom',
                'title' => 'Review marketing strategy — ' . $daysOnMarket . ' days on market',
                'reasoning' => "This property has been listed for {$daysOnMarket} days. Average for the area is approximately 30 days. Consider refreshing marketing photos, adjusting pricing, or reviewing the listing description.",
                'action' => 'Schedule marketing review meeting with seller',
                'seller_title' => 'Marketing strategy review',
                'seller_reasoning' => "Your property has been on the market for {$daysOnMarket} days. Your agent recommends reviewing the marketing approach to attract more interest.",
            ];
        }

        // Many viewings, no offers
        if ($viewingCount >= 5 && $feedbackCount > 0) {
            $concerns = CalendarEventFeedback::where('property_id', $property->id)
                ->whereNotNull('concern_option_ids')
                ->pluck('concern_option_ids')
                ->flatten()
                ->filter()
                ->countBy();

            if ($concerns->isNotEmpty()) {
                $topConcern = $concerns->sortDesc()->keys()->first();
                $topLabel = DB::table('agency_feedback_options')->where('id', $topConcern)->value('label') ?? 'price';
                $recs[] = [
                    'code' => 'viewings_no_offer_concern',
                    'title' => "{$viewingCount} viewings — recurring concern: {$topLabel}",
                    'reasoning' => "Buyers have viewed this property {$viewingCount} times. The most common concern raised is \"{$topLabel}\". Consider discussing this with the seller.",
                    'action' => "Discuss {$topLabel} concern with seller",
                    'seller_title' => 'Buyer feedback summary',
                    'seller_reasoning' => "After {$viewingCount} viewings, buyers have consistently noted \"{$topLabel}\" as their primary consideration.",
                ];
            }
        }

        // No viewings after 14+ days
        if ($daysOnMarket > 14 && $viewingCount === 0) {
            $recs[] = [
                'code' => 'no_viewings',
                'title' => 'No viewings after ' . $daysOnMarket . ' days',
                'reasoning' => 'This property has had zero viewing events recorded despite being listed for ' . $daysOnMarket . ' days. This may indicate a pricing issue, poor portal visibility, or marketing gap.',
                'action' => 'Review portal performance and pricing competitiveness',
                'seller_title' => 'Activity review needed',
                'seller_reasoning' => 'Your agent is reviewing why this property hasn\'t generated viewing requests yet.',
            ];
        }

        return $recs;
    }
}
