<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\PropertyHealthScore;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class PropertyHealthCalculator
{
    /**
     * Calculate health score for a single property.
     */
    public function calculate(Property $property): PropertyHealthScore
    {
        $score   = 100;
        $factors = [];

        // Factor 1: Last activity recency. Whole calendar days idle — NOT Carbon 3's
        // signed float (a negative float is never > 30, so a genuinely-idle property
        // silently scored as healthy). See App\Support\HumanDiff.
        $lastActivity = $property->last_activity_at ?? $property->updated_at;
        $daysSince    = $lastActivity ? \App\Support\HumanDiff::daysBetween($lastActivity) : 999;

        if ($daysSince > 30) {
            $penalty = 25;
            $factors['activity'] = ['label' => 'No activity in ' . \App\Support\HumanDiff::days($lastActivity), 'penalty' => $penalty, 'status' => 'critical'];
            $score -= $penalty;
        } elseif ($daysSince > 14) {
            $penalty = 15;
            $factors['activity'] = ['label' => 'No activity in ' . \App\Support\HumanDiff::days($lastActivity), 'penalty' => $penalty, 'status' => 'warning'];
            $score -= $penalty;
        } elseif ($daysSince > 7) {
            $penalty = 5;
            $factors['activity'] = ['label' => 'Last activity ' . \App\Support\HumanDiff::days($lastActivity) . ' ago', 'penalty' => $penalty, 'status' => 'info'];
            $score -= $penalty;
        } else {
            $factors['activity'] = ['label' => 'Activity recent', 'penalty' => 0, 'status' => 'good'];
        }

        // Factor 2: Documents uploaded
        $docCount = DB::table('property_files')->where('property_id', $property->id)->count();
        if ($docCount === 0) {
            $penalty = 20;
            $factors['documents'] = ['label' => 'No documents uploaded', 'penalty' => $penalty, 'status' => 'critical'];
            $score -= $penalty;
        } elseif ($docCount < 3) {
            $penalty = 10;
            $factors['documents'] = ['label' => "Only {$docCount} document(s)", 'penalty' => $penalty, 'status' => 'warning'];
            $score -= $penalty;
        } else {
            $factors['documents'] = ['label' => "{$docCount} documents", 'penalty' => 0, 'status' => 'good'];
        }

        // Factor 3: Photos
        $photoCount = 0;
        try {
            if (\Schema::hasTable('property_images')) {
                $photoCount = DB::table('property_images')->where('property_id', $property->id)->count();
            }
        } catch (\Throwable $e) {
            $photoCount = 0;
        }
        if ($photoCount < 5) {
            $penalty = 10;
            $factors['photos'] = ['label' => "Only {$photoCount} photo(s)", 'penalty' => $penalty, 'status' => 'warning'];
            $score -= $penalty;
        } else {
            $factors['photos'] = ['label' => "{$photoCount} photos", 'penalty' => 0, 'status' => 'good'];
        }

        // Factor 4: Contact/owner linked
        $ownerLinked = DB::table('contact_property')
            ->where('property_id', $property->id)
            ->whereIn('role', ['owner', 'lessor', 'landlord', 'seller'])
            ->exists();

        if (!$ownerLinked) {
            $penalty = 15;
            $factors['owner'] = ['label' => 'No owner/landlord linked', 'penalty' => $penalty, 'status' => 'critical'];
            $score -= $penalty;
        } else {
            $factors['owner'] = ['label' => 'Owner linked', 'penalty' => 0, 'status' => 'good'];
        }

        // Factor 5: Agent assigned
        if (!$property->agent_id) {
            $penalty = 20;
            $factors['agent'] = ['label' => 'No agent assigned', 'penalty' => $penalty, 'status' => 'critical'];
            $score -= $penalty;
        } else {
            $factors['agent'] = ['label' => 'Agent assigned', 'penalty' => 0, 'status' => 'good'];
        }

        // Factor 6: Price set
        if (!$property->price && !$property->rental_amount) {
            $penalty = 10;
            $factors['price'] = ['label' => 'No price set', 'penalty' => $penalty, 'status' => 'warning'];
            $score -= $penalty;
        } else {
            $factors['price'] = ['label' => 'Price set', 'penalty' => 0, 'status' => 'good'];
        }

        $score = max(0, min(100, $score));
        $grade = PropertyHealthScore::gradeFromScore($score);

        return PropertyHealthScore::updateOrCreate(
            ['property_id' => $property->id],
            [
                'score'              => $score,
                'grade'              => $grade,
                'factors'            => $factors,
                'last_calculated_at' => now(),
            ]
        );
    }

    /**
     * Calculate health for all active properties.
     */
    public function calculateAll(): int
    {
        $count = 0;
        Property::whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')
                  ->orWhereNotIn('status', ['sold', 'withdrawn', 'archived']);
            })
            ->chunk(100, function ($properties) use (&$count) {
                foreach ($properties as $property) {
                    try {
                        $this->calculate($property);
                        $count++;
                    } catch (\Throwable $e) {
                        \Log::warning("Health calc failed for property #{$property->id}: {$e->getMessage()}");
                    }
                }
            });

        return $count;
    }

    /**
     * Get properties needing attention for a user (agent view) or branch/all.
     */
    public function getNeedingAttention(?int $userId = null, ?int $branchId = null, int $limit = 10): \Illuminate\Support\Collection
    {
        $query = PropertyHealthScore::needsAttention()
            ->with('property.agent')
            ->orderBy('score');

        if ($userId) {
            $query->whereHas('property', fn ($q) => $q->where('agent_id', $userId));
        } elseif ($branchId) {
            $query->whereHas('property', fn ($q) => $q->where('branch_id', $branchId));
        }

        return $query->limit($limit)->get();
    }
}
