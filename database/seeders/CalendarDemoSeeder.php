<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CalendarDemoSeeder — V2
 *
 * Seeds ~200 realistic manual calendar events for HFC across all branches
 * and agents. Writes to calendar_event_links pivot directly.
 *
 * Idempotent: wipes demo events + their links + feedback before re-seeding.
 *
 * Run: php artisan db:seed --class=CalendarDemoSeeder
 */
class CalendarDemoSeeder extends Seeder
{
    private const AGENCY_ID = 1;
    private const TARGET_EVENT_COUNT = 200;

    private const EVENT_PROFILES = [
        'viewing' => [
            'title_template'    => 'Viewing — :address',
            'time_window'       => [8, 17],
            'duration_min'      => 60,
            'weight'            => 40,
            'requires_property' => true,
            'requires_contact'  => true,
            'multi_buyer_pct'   => 10, // % of viewings with 2+ contacts
        ],
        'property_evaluation' => [
            'title_template'    => 'Property evaluation — :address',
            'time_window'       => [9, 16],
            'duration_min'      => 90,
            'weight'            => 25,
            'requires_property' => true,
            'requires_contact'  => true,
            'multi_buyer_pct'   => 0,
        ],
        'listing_presentation' => [
            'title_template'    => 'Listing presentation — :contact',
            'time_window'       => [10, 15],
            'duration_min'      => 90,
            'weight'            => 25,
            'requires_property' => false,
            'requires_contact'  => true,
            'multi_buyer_pct'   => 0,
        ],
        'meeting' => [
            'title_template'    => 'Meeting — :contact',
            'time_window'       => [8, 17],
            'duration_min'      => 60,
            'weight'            => 7,
            'requires_property' => false,
            'requires_contact'  => true,
            'multi_buyer_pct'   => 0,
        ],
        'task' => [
            'title_template'    => 'Task — :contact',
            'time_window'       => [8, 18],
            'duration_min'      => 30,
            'weight'            => 3,
            'requires_property' => false,
            'requires_contact'  => false,
            'multi_buyer_pct'   => 0,
        ],
    ];

    private const FEEDBACK_SAMPLES = [
        ['outcome' => 'Interested', 'concerns' => ['Price'], 'seller_visible' => 'Buyer liked the layout, asked about the school zone.', 'internal' => 'Will follow up with bond pre-approval check.'],
        ['outcome' => 'Interested', 'concerns' => [], 'seller_visible' => 'Buyer loved the property and wants to make an offer.', 'internal' => 'Requesting OTP draft.'],
        ['outcome' => 'Not interested', 'concerns' => ['Condition'], 'seller_visible' => 'Concern raised about damp in the garage.', 'internal' => 'Recommend seller addresses damp before next viewing.'],
        ['outcome' => 'Not interested', 'concerns' => ['Layout'], 'seller_visible' => 'Open-plan layout did not suit the buyer.', 'internal' => 'Buyer wants single-storey traditional layout.'],
        ['outcome' => 'Not interested', 'concerns' => ['Location'], 'seller_visible' => 'Buyer felt the location was not right for them.', 'internal' => 'Too far from school. Move to north side suburbs.'],
        ['outcome' => 'No-show', 'concerns' => [], 'seller_visible' => 'Viewing did not take place — buyer did not arrive.', 'internal' => 'Buyer cancelled last minute. Will re-engage.'],
        ['outcome' => 'Made offer', 'concerns' => ['Price'], 'seller_visible' => 'Buyer made an offer below asking — under negotiation.', 'internal' => 'Offer received — discussing counter with seller.'],
        ['outcome' => 'Interested', 'concerns' => ['Size'], 'seller_visible' => 'Buyer would prefer something slightly larger but interested.', 'internal' => 'Show larger stock in same suburb.'],
        ['outcome' => 'Interested', 'concerns' => ['Parking'], 'seller_visible' => 'Good property but limited parking a concern.', 'internal' => 'Check if garage conversion possible.'],
        ['outcome' => 'Not interested', 'concerns' => ['Price', 'Size'], 'seller_visible' => 'Too small for the price point.', 'internal' => 'Buyer needs 4-bed. Update profile.'],
        ['outcome' => 'Rescheduled', 'concerns' => [], 'seller_visible' => 'Rescheduled to next week — buyer had conflict.', 'internal' => 'New date confirmed for Thursday.'],
        ['outcome' => 'Cancelled', 'concerns' => [], 'seller_visible' => 'Buyer cancelled — found another property.', 'internal' => 'Lost to competitor listing in same area.'],
    ];

    private const TASK_TITLES = [
        'Follow up with seller on price reduction',
        'Send comparable sales to buyer',
        'Confirm appointment time',
        'Prepare CMA pack for presentation',
        'Upload mandate documents',
        'Schedule photographer visit',
        'Check bond pre-approval status',
        'Send viewing feedback to seller',
    ];

    public function run(): void
    {
        $this->command->info('Wiping existing demo data...');

        // Get demo event IDs before delete (for link/feedback cleanup)
        $demoIds = DB::table('calendar_events')->where('source_type', 'manual:demo')->pluck('id');

        if ($demoIds->isNotEmpty()) {
            DB::table('calendar_event_links')->whereIn('calendar_event_id', $demoIds)->delete();
            DB::table('calendar_event_feedback')->whereIn('calendar_event_id', $demoIds)->delete();
            DB::table('calendar_event_audit_log')->whereIn('calendar_event_id', $demoIds)->delete();
            DB::table('command_tasks')->where('source_type', 'calendar:missed_feedback')
                ->whereIn('calendar_event_id', $demoIds)->delete();
        }
        $deleted = DB::table('calendar_events')->where('source_type', 'manual:demo')->delete();
        $this->command->info("Deleted {$deleted} demo events + related links/feedback/tasks.");

        $branches = DB::table('branches')->where('agency_id', self::AGENCY_ID)->whereNull('deleted_at')->get();
        $agents = DB::table('users')->where('agency_id', self::AGENCY_ID)->whereNull('deleted_at')->whereNotNull('branch_id')->get()->groupBy('branch_id');
        $properties = DB::table('properties')->where('agency_id', self::AGENCY_ID)->whereNull('deleted_at')->get();
        $contacts = DB::table('contacts')->where('agency_id', self::AGENCY_ID)->whereNull('deleted_at')->get();
        $outcomeMap = DB::table('agency_feedback_options')->where('category', 'outcome')->whereNull('agency_id')->pluck('id', 'label')->toArray();

        if ($agents->isEmpty() || $contacts->isEmpty()) {
            $this->command->warn('No agents or contacts found. Aborting.');
            return;
        }

        $created = 0;
        $linkInserts = [];
        $feedbackInserts = [];
        $now = now();

        while ($created < self::TARGET_EVENT_COUNT) {
            $branch = $branches->random();
            $branchAgents = $agents->get($branch->id);
            if (!$branchAgents || $branchAgents->isEmpty()) continue;

            $category = $this->pickWeightedCategory();
            $profile = self::EVENT_PROFILES[$category];

            if ($profile['requires_property'] && $properties->isEmpty()) continue;

            $agent = $branchAgents->random();
            $contact = $profile['requires_contact'] ? $contacts->random() : null;
            $property = $profile['requires_property'] ? $properties->random() : null;

            [$eventDate, $isPast, $hasFeedback] = $this->pickEventDate($profile['time_window']);

            $title = $this->buildTitle($profile, $category, $property, $contact);

            $metadata = ['demo' => true, 'demo_seeded_at' => $now->toIso8601String()];

            $status = 'pending';
            if ($isPast && $hasFeedback) {
                $status = 'completed';
            }

            $eventId = DB::table('calendar_events')->insertGetId([
                'event_type'    => 'manual',
                'category'      => $category,
                'title'         => $title,
                'description'   => null,
                'event_date'    => $eventDate,
                'end_date'      => $eventDate->copy()->addMinutes($profile['duration_min']),
                'all_day'       => false,
                'priority'      => 'normal',
                'send_reminder' => true,
                'status'        => $status,
                'source_type'   => 'manual:demo',
                'user_id'       => $agent->id,
                'property_id'   => $property?->id,
                'contact_id'    => $contact?->id,
                'agency_id'     => self::AGENCY_ID,
                'branch_id'     => $branch->id,
                'metadata'      => json_encode($metadata),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            // Write links to pivot
            if ($property) {
                $linkInserts[] = [
                    'calendar_event_id' => $eventId,
                    'linkable_type' => 'App\\Models\\Property',
                    'linkable_id' => $property->id,
                    'role' => 'subject_property',
                    'agency_id' => self::AGENCY_ID,
                    'created_by_user_id' => $agent->id,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            $contactIds = [];
            if ($contact) {
                $contactIds[] = $contact->id;
                // Multi-buyer: ~10% of viewings get extra contacts
                if ($category === 'viewing' && mt_rand(1, 100) <= ($profile['multi_buyer_pct'] ?? 0) && $contacts->count() > 1) {
                    $extra = $contacts->where('id', '!=', $contact->id)->random();
                    $contactIds[] = $extra->id;
                }
                foreach ($contactIds as $cid) {
                    $linkInserts[] = [
                        'calendar_event_id' => $eventId,
                        'linkable_type' => 'App\\Models\\Contact',
                        'linkable_id' => $cid,
                        'role' => 'attendee',
                        'agency_id' => self::AGENCY_ID,
                        'created_by_user_id' => $agent->id,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            // Feedback for past completed events
            if ($isPast && $hasFeedback && $contact) {
                $fb = self::FEEDBACK_SAMPLES[array_rand(self::FEEDBACK_SAMPLES)];
                foreach ($contactIds as $cid) {
                    $feedbackInserts[] = [
                        'calendar_event_id' => $eventId,
                        'contact_id' => $cid,
                        'outcome_option_id' => $outcomeMap[$fb['outcome']] ?? null,
                        'concern_option_ids' => json_encode($fb['concerns']),
                        'seller_visible_notes' => $fb['seller_visible'],
                        'internal_notes' => $fb['internal'],
                        'captured_by_user_id' => $agent->id,
                        'captured_at' => $eventDate->copy()->addHours(2),
                        'agency_id' => self::AGENCY_ID,
                        'branch_id' => $branch->id,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            $created++;
        }

        // Bulk insert links + feedback
        foreach (array_chunk($linkInserts, 500) as $chunk) {
            DB::table('calendar_event_links')->insert($chunk);
        }
        foreach (array_chunk($feedbackInserts, 500) as $chunk) {
            DB::table('calendar_event_feedback')->insert($chunk);
        }

        $this->command->info("Seeded {$created} demo events with " . count($linkInserts) . " links + " . count($feedbackInserts) . " feedback rows.");

        // Summary
        $byCategory = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->selectRaw('category, COUNT(*) as cnt')
            ->groupBy('category')->get();
        foreach ($byCategory as $row) $this->command->info("  {$row->category}: {$row->cnt}");

        $byBranch = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->selectRaw('branch_id, COUNT(*) as cnt')
            ->groupBy('branch_id')->get();
        foreach ($byBranch as $row) $this->command->info("  branch {$row->branch_id}: {$row->cnt}");
    }

    private function pickWeightedCategory(): string
    {
        $r = mt_rand(1, 100);
        $cumulative = 0;
        foreach (self::EVENT_PROFILES as $cat => $profile) {
            $cumulative += $profile['weight'];
            if ($r <= $cumulative) return $cat;
        }
        return 'viewing';
    }

    private function pickEventDate(array $timeWindow): array
    {
        $r = mt_rand(1, 100);

        if ($r <= 36) {
            // Past WITH feedback (60% of past = 36% of total)
            $date = now()->subDays(mt_rand(1, 60));
            return [$this->setRealisticTime($date, $timeWindow), true, true];
        }

        if ($r <= 60) {
            // Past WITHOUT feedback (40% of past = 24% of total)
            $date = now()->subDays(mt_rand(1, 30));
            return [$this->setRealisticTime($date, $timeWindow), true, false];
        }

        // Future (40% of total)
        $date = now()->addDays(mt_rand(1, 90));
        return [$this->setRealisticTime($date, $timeWindow), false, false];
    }

    private function setRealisticTime(Carbon $date, array $hourRange): Carbon
    {
        $hour = mt_rand($hourRange[0], $hourRange[1]);
        $minute = [0, 30][mt_rand(0, 1)]; // 30-min alignment
        return $date->copy()->setTime($hour, $minute, 0);
    }

    private function buildTitle(array $profile, string $category, $property, $contact): string
    {
        if ($category === 'task') {
            return self::TASK_TITLES[array_rand(self::TASK_TITLES)];
        }

        $title = $profile['title_template'];
        $address = $property->address ?? $property->suburb ?? 'property';
        if (empty(trim($address))) $address = $property->suburb ?? 'property';
        $contactName = $contact ? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) : '';
        if (empty($contactName)) $contactName = 'contact';

        return str_replace([':address', ':contact'], [$address, $contactName], $title);
    }
}
