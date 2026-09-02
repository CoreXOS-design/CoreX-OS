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

    /**
     * Johan, 2026-09-02 — Thursday-webinar calendar/feedback fix.
     *
     * Two changes from the original version of this seeder:
     *  1. Wrapped the whole run in a DB transaction. cc4's earlier seeder
     *     crash left duplicate properties/market reports on demo from a
     *     partial run — wiping the OLD batch, then crashing before the NEW
     *     batch finished, is the exact same failure shape here (would leave
     *     zero or a half batch of appointments). A transaction makes a crash
     *     roll back to the pre-run state instead of leaving debris; re-running
     *     after a crash reproduces the same clean wipe-and-reseed every time.
     *  2. Every delete below is now a SOFT delete (deleted_at stamped), never
     *     a hard DB::table()->delete() — CoreX's no-hard-deletes rule applies
     *     to demo too. calendar_event_audit_log has no deleted_at column at
     *     all, so those rows are left alone entirely (an audit trail is
     *     append-only by nature; stale rows pointing at an archived event are
     *     harmless, not deleted). The showcase event stageViewingFeedback_
     *     demoShowcase() builds (title below) is excluded from the wipe by
     *     name so running this seeder standalone never disturbs it.
     */
    private const SHOWCASE_TITLE = '[DEMO] Multi-Property Viewing — Feedback Showcase';

    public function run(): void
    {
        DB::transaction(function () {
            $this->seedCalendarDemoData();
        });
    }

    private function seedCalendarDemoData(): void
    {
        $this->command->info('Archiving existing demo appointments (soft delete)...');

        $now0 = now();

        // Demo event IDs to archive — every manual:demo event EXCEPT the
        // separate feedback showcase (a different, already-idempotent stage
        // owns that one; see the docblock above).
        $demoIds = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->where('title', '!=', self::SHOWCASE_TITLE)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($demoIds->isNotEmpty()) {
            DB::table('calendar_event_links')->whereIn('calendar_event_id', $demoIds)
                ->whereNull('deleted_at')->update(['deleted_at' => $now0, 'updated_at' => $now0]);
            DB::table('calendar_event_feedback')->whereIn('calendar_event_id', $demoIds)
                ->whereNull('deleted_at')->update(['deleted_at' => $now0, 'updated_at' => $now0]);
            DB::table('command_tasks')->where('source_type', 'calendar:missed_feedback')
                ->whereIn('calendar_event_id', $demoIds)
                ->whereNull('deleted_at')->update(['deleted_at' => $now0, 'updated_at' => $now0]);
            // calendar_event_audit_log: no deleted_at column — left untouched, on purpose (see docblock).
        }
        $archived = DB::table('calendar_events')
            ->whereIn('id', $demoIds)
            ->update(['deleted_at' => $now0, 'updated_at' => $now0]);
        $this->command->info("Archived {$archived} demo events + related links/feedback/tasks (soft delete — nothing removed from the DB).");

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
                        // feedback_kind mirrors the appointment's own category (viewing/
                        // property_evaluation/listing_presentation/meeting) — the column
                        // defaults to 'viewing' when omitted, which was silently wrong for
                        // every non-viewing appointment's feedback before this.
                        'feedback_kind' => $category,
                        // property_id: the single property this appointment was about,
                        // when it had one (viewing/property_evaluation always do; listing_
                        // presentation/meeting never do — property stays null for those,
                        // exactly as it should).
                        'property_id' => $property?->id,
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

        // Summary — whereNull('deleted_at') is NOT optional here: DB::table()
        // is the raw query builder, not the Eloquent model, so it does not
        // auto-exclude soft-deleted rows the way Eloquent's SoftDeletes trait
        // would. Without this filter the just-archived batch above still
        // shows up in these counts.
        $byCategory = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->whereNull('deleted_at')
            ->selectRaw('category, COUNT(*) as cnt')
            ->groupBy('category')->get();
        foreach ($byCategory as $row) $this->command->info("  {$row->category}: {$row->cnt}");

        $byBranch = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->whereNull('deleted_at')
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
