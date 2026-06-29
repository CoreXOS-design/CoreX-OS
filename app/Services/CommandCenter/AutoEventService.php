<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoEventService
{
    /**
     * Called when a property is created — generate document expectation tasks.
     */
    public function onPropertyCreated(Property $property): void
    {
        if (!$property->agent_id) {
            return;
        }

        // Bulk-imported stock opts out of new-listing automation. Imported
        // inventory is not a freshly captured mandate to chase to compliance,
        // so it must not spawn document-chase chore tasks (the build-up that
        // OOM'd the Tasks board on staging). The importer sets this flag on the
        // model before save; genuine new mandates leave it false. See
        // Property::$skipNewListingAutomation.
        if ($property->skipNewListingAutomation) {
            return;
        }

        // A property created already-compliant (e.g. imported stock stamped
        // compliant up front) doesn't need document-chase tasks. The P24
        // importer sets compliance AFTER create, so this guard catches the
        // create-already-compliant path; DismissComplianceClearedChores
        // handles the create-then-mark-compliant path.
        if ($property->compliance_snapshot_at !== null) {
            return;
        }

        $agentId  = $property->agent_id;
        $branchId = $property->branch_id;
        $agencyId = $property->agency_id;

        // Load document expectations for this property type
        $listingType = $property->listing_type ?? 'sale';
        $expectations = DB::table('command_document_expectations')
            ->where('property_type', $listingType)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get();

        foreach ($expectations as $exp) {
            CommandTask::create([
                'title'       => $exp->label . ' — ' . ($property->buildDisplayAddress() ?: 'Property #' . $property->id),
                'task_type'   => 'document_upload',
                'priority'    => $exp->required ? 'high' : 'normal',
                'assigned_to' => $agentId,
                'due_date'    => now()->addHours($exp->due_offset_hours),
                'property_id' => $property->id,
                'source_type' => 'automation_rule',
                'branch_id'   => $branchId,
                'agency_id'   => $agencyId,
            ]);
        }

        // If no expectations configured, create sensible defaults
        if ($expectations->isEmpty()) {
            $defaults = $listingType === 'rental'
                ? ['Prepare lease agreement', 'Upload landlord FICA documents']
                : ['Upload signed mandate', 'Upload owner ID copy', 'Upload proof of ownership'];

            foreach ($defaults as $i => $label) {
                CommandTask::create([
                    'title'       => $label . ' — ' . ($property->buildDisplayAddress() ?: 'Property #' . $property->id),
                    'task_type'   => 'document_upload',
                    'priority'    => 'high',
                    'assigned_to' => $agentId,
                    'due_date'    => now()->addHours(72),
                    'property_id' => $property->id,
                    'source_type' => 'automation_rule',
                    'branch_id'   => $branchId,
                    'agency_id'   => $agencyId,
                ]);
            }
        }
    }

    /**
     * Called when a property is updated — update last_activity_at.
     */
    public function onPropertyUpdated(Property $property): void
    {
        $property->updateQuietly(['last_activity_at' => now()]);
    }

    /**
     * Generate events for a deal (V2) being created.
     */
    public function onDealCreated($deal): void
    {
        if (!$deal->agent_id && !$deal->created_by) {
            return;
        }

        $agentId  = $deal->agent_id ?? $deal->created_by;
        $address  = 'Deal #' . $deal->id;

        if ($deal->property_id) {
            $prop = Property::find($deal->property_id);
            if ($prop) {
                $address = $prop->buildDisplayAddress() ?: $address;
            }
        }

        // Create FICA task for all parties on the deal
        $parties = [];
        if (\Schema::hasTable('deal_v2_contacts')) {
            $parties = DB::table('deal_v2_contacts')
                ->where('deal_id', $deal->id)
                ->join('contacts', 'contacts.id', '=', 'deal_v2_contacts.contact_id')
                ->get(['contacts.id as contact_id', 'contacts.first_name', 'contacts.last_name', 'deal_v2_contacts.role']);
        }

        foreach ($parties as $party) {
            CommandTask::create([
                'title'       => "Complete FICA for {$party->first_name} {$party->last_name} — {$address}",
                'task_type'   => 'compliance',
                'priority'    => 'high',
                'assigned_to' => $agentId,
                'due_date'    => now()->addDays(7),
                'property_id' => $deal->property_id,
                'contact_id'  => $party->contact_id,
                'deal_id'     => $deal->id,
                'source_type' => 'automation_rule',
            ]);
        }

        // Bond application task for sales
        $dealType = $deal->deal_type ?? '';
        if (stripos($dealType, 'sale') !== false || stripos($dealType, 'purchase') !== false) {
            CommandTask::create([
                'title'       => "Submit bond application — {$address}",
                'task_type'   => 'deal_action',
                'priority'    => 'high',
                'assigned_to' => $agentId,
                'due_date'    => now()->addDays(3),
                'property_id' => $deal->property_id,
                'deal_id'     => $deal->id,
                'source_type' => 'automation_rule',
            ]);
        }

        // Calendar event for deal creation
        CalendarEvent::create([
            'user_id'     => $agentId,
            'event_type'  => 'deal',
            'category'    => 'deal_created',
            'title'       => "New Deal: {$address}",
            'event_date'  => now(),
            'all_day'     => true,
            'priority'    => 'normal',
            'status'      => 'completed',
            'source_type' => get_class($deal),
            'source_id'   => $deal->id,
            'property_id' => $deal->property_id,
        ]);
    }

    /**
     * Backfill events from existing properties.
     */
    public function backfillProperties(): int
    {
        $count = 0;
        Property::whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')
                  ->orWhereNotIn('status', ['sold', 'withdrawn', 'archived']);
            })
            ->chunk(50, function ($properties) use (&$count) {
                foreach ($properties as $property) {
                    // Only create if no events exist for this property yet
                    $exists = CalendarEvent::where('property_id', $property->id)->exists();
                    if ($exists) continue;

                    if ($property->agent_id) {
                        CalendarEvent::create([
                            'user_id'     => $property->agent_id,
                            'event_type'  => 'property',
                            'category'    => 'listing_created',
                            'title'       => 'Listing: ' . ($property->buildDisplayAddress() ?: 'Property #' . $property->id),
                            'event_date'  => $property->created_at ?? now(),
                            'all_day'     => true,
                            'status'      => 'completed',
                            'source_type' => Property::class,
                            'source_id'   => $property->id,
                            'property_id' => $property->id,
                            'branch_id'   => $property->branch_id,
                            'agency_id'   => $property->agency_id,
                        ]);
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Scan for idle properties and create attention tasks.
     */
    public function flagIdleProperties(int $thresholdDays = 14, int $criticalDays = 30): array
    {
        $flagged  = 0;
        $critical = 0;

        Property::whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')
                  ->orWhereNotIn('status', ['sold', 'withdrawn', 'archived']);
            })
            // Compliant stock (e.g. P24 go-live imports) is vetted/established
            // and must not be flagged "needs attention" — that flooded the
            // cockpit with hundreds of idle prompts for freshly imported,
            // already-compliant listings.
            ->whereNull('compliance_snapshot_at')
            ->whereNotNull('agent_id')
            ->where(function ($q) use ($thresholdDays) {
                $q->where('last_activity_at', '<', now()->subDays($thresholdDays))
                  ->orWhereNull('last_activity_at');
            })
            ->chunk(50, function ($properties) use (&$flagged, &$critical, $criticalDays) {
                foreach ($properties as $property) {
                    $lastActivity = $property->last_activity_at ?? $property->updated_at;
                    $daysSince = $lastActivity ? now()->diffInDays($lastActivity) : 999;
                    $isCritical = $daysSince >= $criticalDays;

                    // Check if an idle task already exists and is still open
                    $existing = CommandTask::where('property_id', $property->id)
                        ->where('task_type', 'review')
                        ->whereIn('status', ['todo', 'in_progress'])
                        ->where('title', 'like', '%no activity%')
                        ->exists();

                    if ($existing) continue;

                    CommandTask::create([
                        'title'       => ($isCritical ? 'URGENT: ' : '') . "Property needs attention — no activity in {$daysSince} days — " . ($property->buildDisplayAddress() ?: 'Property #' . $property->id),
                        'task_type'   => 'review',
                        'priority'    => $isCritical ? 'critical' : 'high',
                        'assigned_to' => $property->agent_id,
                        'due_date'    => now()->addDays(2),
                        'property_id' => $property->id,
                        'source_type' => 'automation_rule',
                        'branch_id'   => $property->branch_id,
                        'agency_id'   => $property->agency_id,
                    ]);

                    if ($isCritical) $critical++;
                    $flagged++;
                }
            });

        return ['flagged' => $flagged, 'critical' => $critical];
    }
}
