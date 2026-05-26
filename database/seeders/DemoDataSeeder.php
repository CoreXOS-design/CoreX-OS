<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    private int $agencyId = 1;
    private array $agentIds;
    private array $branchIds;
    private array $suburbs = ['Margate', 'Uvongo', 'Southbroom', 'Shelly Beach', 'Ramsgate', 'Port Edward', 'Manaba'];

    public function run(): void
    {
        if (!in_array(app()->environment(), ['local', 'demo'], true)) {
            throw new \RuntimeException('DemoDataSeeder can only run in local or demo environment. Current: ' . app()->environment());
        }

        $this->agentIds = DB::table('users')
            ->where('agency_id', $this->agencyId)->where('is_active', 1)
            ->whereNotIn('role', ['super_admin', 'owner'])
            ->pluck('id')->toArray();

        $this->branchIds = DB::table('branches')
            ->where('agency_id', $this->agencyId)->pluck('id')->toArray();

        $this->command->info('Seeding demo data (local only)...');

        DB::transaction(function () {
            $this->seedContactMatchesWithPreapproval();
            $this->seedDemoContacts();
            $this->seedDemoProperties();
            $this->seedDemoCalendarEvents();
            $this->seedDemoBuyerActivity();
        });

        $this->command->info('Running match recompute...');
        \Artisan::call('prospecting:recompute-matches');
        $this->command->info(\Artisan::output());

        $this->command->info('Demo data seeded successfully.');
    }

    // ─── A. CONTACT MATCHES + PREAPPROVAL (enriching real buyers) ────────
    // Post-unification (spec D1, D3): wishlists live on contact_matches, preapproval
    // on contacts. Old seedBuyerPreferences() wrote to buyer_preferences.

    private function seedContactMatchesWithPreapproval(): void
    {
        // Pick buyers who do not yet have a ContactMatch.
        $buyerIds = DB::table('contacts')
            ->where('contacts.agency_id', $this->agencyId)
            ->where('contacts.is_buyer', 1)
            ->whereNull('contacts.deleted_at')
            ->leftJoin('contact_matches', function ($j) {
                $j->on('contacts.id', '=', 'contact_matches.contact_id')
                  ->whereNull('contact_matches.deleted_at');
            })
            ->whereNull('contact_matches.id')
            ->pluck('contacts.id')->toArray();

        $presets = [
            ['price_min' => 1500000, 'price_max' => 2500000, 'beds_min' => 2, 'bedrooms_max' => 3, 'suburbs' => ['Margate', 'Uvongo'], 'must_have_features' => ['garden']],
            ['price_min' => 3000000, 'price_max' => 5000000, 'beds_min' => 4, 'bedrooms_max' => 5, 'suburbs' => ['Southbroom', 'Shelly Beach'], 'must_have_features' => ['pool', 'sea_view'], 'preapproval_amount' => 4500000, 'preapproval_institution' => 'Standard Bank', 'preapproval_expires_at' => now()->addMonths(2)->toDateString()],
            ['price_min' => 800000,  'price_max' => 1500000, 'beds_min' => 1, 'bedrooms_max' => 2, 'suburbs' => ['Margate'], 'property_types' => ['Apartment'], 'must_have_features' => ['pet_friendly']],
            ['price_min' => 5000000, 'price_max' => 8000000, 'beds_min' => 4, 'bedrooms_max' => 5, 'suburbs' => ['Southbroom'], 'preapproval_amount' => 6000000, 'preapproval_institution' => 'Investec', 'preapproval_expires_at' => now()->addMonths(3)->toDateString()],
            ['price_min' => 2000000, 'price_max' => 3500000, 'beds_min' => 3, 'bedrooms_max' => 3, 'suburbs' => ['Margate', 'Uvongo', 'Shelly Beach', 'Ramsgate'], 'must_have_features' => ['security']],
            ['price_min' => 1200000, 'price_max' => 2000000, 'beds_min' => 2, 'bedrooms_max' => 3, 'suburbs' => ['Uvongo', 'Margate'], 'property_types' => ['Townhouse'], 'preapproval_amount' => 1800000, 'preapproval_institution' => 'SA Home Loans', 'preapproval_expires_at' => now()->addDays(45)->toDateString()],
            ['price_min' => 4000000, 'price_max' => 6000000, 'beds_min' => 4, 'bedrooms_max' => 4, 'suburbs' => ['Southbroom', 'Shelly Beach'], 'must_have_features' => ['garden', 'pool']],
            ['price_min' => 900000,  'price_max' => 1400000, 'beds_min' => 1, 'bedrooms_max' => 2, 'suburbs' => ['Margate'], 'preapproval_amount' => 1200000, 'preapproval_institution' => 'ooba', 'preapproval_expires_at' => now()->addDays(20)->toDateString()],
            ['price_min' => 2500000, 'price_max' => 4000000, 'beds_min' => 3, 'bedrooms_max' => 4, 'suburbs' => ['Margate', 'Uvongo', 'Ramsgate'], 'must_have_features' => ['pet_friendly']],
            ['price_min' => 6000000, 'price_max' => 10000000, 'beds_min' => 5, 'bedrooms_max' => 6, 'suburbs' => ['Southbroom']],
            ['price_min' => 1800000, 'price_max' => 2800000, 'beds_min' => 3, 'bedrooms_max' => 3, 'suburbs' => ['Uvongo', 'Margate'], 'must_have_features' => ['garage']],
            ['price_min' => 3500000, 'price_max' => 5000000, 'beds_min' => 4, 'bedrooms_max' => 4, 'suburbs' => ['Shelly Beach', 'Margate'], 'preapproval_amount' => 4500000, 'preapproval_institution' => 'Standard Bank', 'preapproval_expires_at' => now()->addMonths(4)->toDateString()],
        ];

        $count = 0;
        foreach ($buyerIds as $i => $buyerId) {
            if ($i >= count($presets)) break;
            $p = $presets[$i];

            // 1. Preapproval → Contact pillar (spec D3).
            $contactUpdates = array_filter([
                'preapproval_amount'      => $p['preapproval_amount']      ?? null,
                'preapproval_expires_at'  => $p['preapproval_expires_at']  ?? null,
                'preapproval_institution' => $p['preapproval_institution'] ?? null,
            ], fn ($v) => $v !== null);
            if (!empty($contactUpdates)) {
                DB::table('contacts')->where('id', $buyerId)->update($contactUpdates);
            }

            // 2. Wishlist → contact_matches (Eloquent so the observer fires and
            //    is_primary auto-sets for the first match per contact).
            $propertyTypes = $p['property_types'] ?? [];
            \App\Models\ContactMatch::withoutGlobalScopes()->create([
                'agency_id'             => $this->agencyId,
                'contact_id'            => $buyerId,
                'created_by_user_id'    => 22,
                'updated_by_user_id'    => 22,
                'status'                => \App\Models\ContactMatch::STATUS_ACTIVE,
                'listing_type'          => 'sale',
                'price_min'             => $p['price_min'],
                'price_max'             => $p['price_max'],
                'beds_min'              => $p['beds_min'] ?? null,
                'bedrooms_max'          => $p['bedrooms_max'] ?? null,
                'suburbs'               => $p['suburbs'] ?? [],
                'property_types'        => $propertyTypes,
                'property_type'         => $propertyTypes[0] ?? null,
                'must_have_features'    => $p['must_have_features'] ?? [],
                'nice_to_have_features' => [],
                'deal_breakers'         => [],
            ]);
            $count++;
        }
        $this->command->info("  A. ContactMatches + preapproval seeded: {$count}");
    }

    // ─── B. DEMO CONTACTS (buyers) ──────────────────────────

    private function seedDemoContacts(): void
    {
        $firstNames = ['Thabo', 'Lerato', 'Pieter', 'Anele', 'Michelle', 'Sipho', 'Karen', 'Bongani', 'Tanya', 'Mandla',
                        'Jaco', 'Nomsa', 'Grant', 'Fatima', 'Craig', 'Precious', 'Wayne', 'Zanele', 'Derek', 'Thandiwe',
                        'Rikus', 'Ayanda', 'Wendy', 'Tshepo', 'Liezel', 'Siyabonga', 'Chantal', 'Mpho', 'Gerhard', 'Nontobeko'];
        $lastNames = ['Ndlovu', 'van der Merwe', 'Dlamini', 'Botha', 'Mkhize', 'Joubert', 'Khumalo', 'Pretorius', 'Nkosi', 'Steyn',
                      'Mahlangu', 'du Plessis', 'Zulu', 'Nel', 'Sithole', 'Smit', 'Pillay', 'Bezuidenhout', 'Molefe', 'Venter'];
        $states = array_merge(array_fill(0, 5, 'new'), array_fill(0, 12, 'warm'), array_fill(0, 7, 'cold'), array_fill(0, 6, 'lost'));
        shuffle($states);

        $count = 0;
        for ($i = 0; $i < 30; $i++) {
            $agentId = $this->agentIds[$i % count($this->agentIds)];
            $branchId = $this->branchIds[$i % count($this->branchIds)];
            $state = $states[$i] ?? 'warm';
            $createdAt = now()->subDays(rand(7, 120));
            $lastActivity = $state === 'lost' ? $createdAt->copy()->addDays(rand(5, 30)) : now()->subDays(rand(0, 21));

            $contactId = DB::table('contacts')->insertGetId([
                'agency_id' => $this->agencyId,
                'branch_id' => $branchId,
                'created_by_user_id' => $agentId,
                'first_name' => '[DEMO] ' . $firstNames[$i % count($firstNames)],
                'last_name' => $lastNames[$i % count($lastNames)],
                'phone' => '07' . rand(10000000, 99999999),
                'email' => strtolower($firstNames[$i % count($firstNames)]) . $i . '@demo.test',
                'is_buyer' => true,
                'buyer_state' => $state,
                'last_activity_at' => $lastActivity,
                'buyer_pipeline_entered_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => now(),
            ]);

            // Add a wishlist for ~60% of demo buyers (post-unification: writes to
            // contact_matches + contact preapproval block, not buyer_preferences).
            if ($i < 18) {
                $suburb = $this->suburbs[array_rand($this->suburbs)];
                $suburb2 = $this->suburbs[array_rand($this->suburbs)];
                $budgetBase = [800000, 1200000, 1500000, 2000000, 2500000, 3000000, 4000000, 5000000][rand(0, 7)];

                if ($i % 3 === 0) {
                    DB::table('contacts')->where('id', $contactId)->update([
                        'preapproval_amount'      => $budgetBase + rand(0, 500000),
                        'preapproval_institution' => ['Standard Bank', 'FNB', 'Nedbank', 'ABSA', 'ooba'][rand(0, 4)],
                        'preapproval_expires_at'  => now()->addDays(rand(15, 90))->toDateString(),
                    ]);
                }

                \App\Models\ContactMatch::withoutGlobalScopes()->create([
                    'agency_id'          => $this->agencyId,
                    'contact_id'         => $contactId,
                    'created_by_user_id' => $agentId,
                    'updated_by_user_id' => $agentId,
                    'status'             => \App\Models\ContactMatch::STATUS_ACTIVE,
                    'listing_type'       => 'sale',
                    'price_min'          => $budgetBase,
                    'price_max'          => $budgetBase + rand(500000, 2000000),
                    'beds_min'           => rand(1, 3),
                    'bedrooms_max'       => rand(3, 5),
                    'suburbs'            => array_values(array_unique([$suburb, $suburb2])),
                    'property_types'     => [],
                    'must_have_features' => [],
                    'nice_to_have_features' => [],
                    'deal_breakers'      => [],
                ]);
            }

            // Lost records for lost buyers
            if ($state === 'lost') {
                $reasons = ['found_elsewhere', 'price_too_high', 'area_not_suitable', 'financing_failed', 'changed_mind', 'relocation_cancelled'];
                DB::table('buyer_lost_records')->insert([
                    'contact_id' => $contactId,
                    'agency_id' => $this->agencyId,
                    'reason_code' => $reasons[array_rand($reasons)],
                    'reason_label' => ucfirst(str_replace('_', ' ', $reasons[array_rand($reasons)])),
                    'recorded_by_user_id' => $agentId,
                    'recorded_at' => $lastActivity,
                    'source' => 'manual',
                    'buyer_state_at_loss' => 'cold',
                    'days_in_pipeline_at_loss' => rand(14, 90),
                    'agent_owner_user_id_at_loss' => $agentId,
                    'branch_id_at_loss' => $branchId,
                    'preapproval_amount_at_loss' => rand(0, 1) ? rand(1000000, 5000000) : null,
                    'created_at' => $lastActivity,
                    'updated_at' => $lastActivity,
                ]);
            }
            $count++;
        }
        $this->command->info("  B. Demo buyer contacts seeded: {$count}");
    }

    // ─── C. DEMO PROPERTIES ────────────────────────────────

    private function seedDemoProperties(): void
    {
        $streets = ['Ocean', 'Marine', 'Beach', 'Hibiscus', 'Palm', 'Coral', 'Lighthouse', 'Dolphin', 'Sunset', 'Seaview',
                     'Protea', 'Fynbos', 'Milkwood', 'Sardine', 'Whale', 'Eagle', 'Kingfisher', 'Heron', 'Pelican', 'Albatross'];
        $types = array_merge(array_fill(0, 24, 'House'), array_fill(0, 8, 'Apartment'), array_fill(0, 6, 'Townhouse'), array_fill(0, 2, 'Commercial'));
        shuffle($types);

        $count = 0;
        for ($i = 0; $i < 40; $i++) {
            $agentId = $this->agentIds[$i % count($this->agentIds)];
            $branchId = $this->branchIds[$i % count($this->branchIds)];
            $suburb = $this->suburbs[$i % count($this->suburbs)];
            $type = $types[$i] ?? 'House';
            $beds = $type === 'Apartment' ? rand(1, 2) : ($type === 'Townhouse' ? rand(2, 3) : rand(2, 5));
            $price = match (true) {
                $beds <= 2 => rand(6, 18) * 100000,
                $beds === 3 => rand(15, 40) * 100000,
                $beds === 4 => rand(25, 60) * 100000,
                default => rand(40, 120) * 100000,
            };

            $listedDays = rand(5, 200);
            $status = match (true) {
                $i < 4 => 'draft',
                $i >= 35 => 'sold',
                default => 'available',
            };
            $publishedAt = $status !== 'draft' ? now()->subDays($listedDays) : null;

            DB::table('properties')->insert([
                'external_id' => 'DEMO-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'agency_id' => $this->agencyId,
                'branch_id' => $branchId,
                'agent_id' => $agentId,
                'title' => "[DEMO] {$beds} Bed {$type} in {$suburb}",
                'address' => "[DEMO] {$i} {$streets[$i % count($streets)]} Drive, {$suburb}",
                'suburb' => $suburb,
                'city' => 'KZN South Coast',
                'province' => 'KwaZulu-Natal',
                'property_type' => $type,
                'category' => 'Residential',
                'listing_type' => 'For Sale',
                'status' => $status,
                'price' => $price,
                'beds' => $beds,
                'baths' => max(1, $beds - 1),
                'garages' => $type === 'Apartment' ? 0 : rand(1, 2),
                'erf_size_m2' => $type === 'Apartment' ? null : rand(300, 2000),
                'size_m2' => rand(60, 400),
                'published_at' => $publishedAt,
                'listed_date' => $publishedAt?->toDateString(),
                'mandate_type' => ['Sole', 'Open', 'Dual'][rand(0, 2)],
                'created_at' => now()->subDays($listedDays + rand(0, 10)),
                'updated_at' => now(),
            ]);

            $propId = DB::getPdo()->lastInsertId();

            // Sold records for sold properties
            if ($status === 'sold') {
                DB::table('property_sold_records')->insert([
                    'property_id' => $propId,
                    'agency_id' => $this->agencyId,
                    'sold_price' => (int) ($price * (rand(90, 102) / 100)),
                    'sold_date' => now()->subDays(rand(5, 60))->toDateString(),
                    'days_on_market' => $listedDays,
                    'source' => 'manual',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Marketing activities for active properties (~60%)
            if ($status === 'available' && rand(0, 2) > 0) {
                $activities = ['portal_listed', 'photos_refreshed', 'price_adjusted', 'show_day_held', 'social_share'];
                for ($a = 0; $a < rand(1, 3); $a++) {
                    DB::table('property_marketing_activities')->insert([
                        'property_id' => $propId,
                        'agency_id' => $this->agencyId,
                        'activity_type' => $activities[array_rand($activities)],
                        'occurred_at' => now()->subDays(rand(1, 60)),
                        'logged_by_user_id' => $agentId,
                        'internal_only' => false,
                        'created_at' => now(),
                    ]);
                }
            }
            $count++;
        }
        $this->command->info("  C. Demo properties seeded: {$count}");
    }

    // ─── D. DEMO CALENDAR EVENTS ──────────────────────────

    private function seedDemoCalendarEvents(): void
    {
        $classes = ['viewing', 'viewing', 'viewing', 'viewing', 'listing_presentation', 'listing_presentation',
                    'meeting', 'meeting', 'seller_meeting', 'property_evaluation'];

        $count = 0;
        for ($i = 0; $i < 40; $i++) {
            $agentId = $this->agentIds[$i % count($this->agentIds)];
            $daysOffset = rand(-45, 25);
            $hour = rand(8, 17);
            $eventDate = now()->addDays($daysOffset)->setHour($hour)->setMinute(0)->setSecond(0);
            $class = $classes[$i % count($classes)];
            $status = $daysOffset < -1 ? (rand(0, 2) > 0 ? 'completed' : 'pending') : 'pending';

            DB::table('calendar_events')->insert([
                'agency_id' => $this->agencyId,
                'user_id' => $agentId,
                'title' => "[DEMO] {$class} — " . $this->suburbs[array_rand($this->suburbs)],
                'category' => $class,
                'event_type' => 'manual',
                'source_type' => 'manual:demo',
                'event_date' => $eventDate,
                'end_date' => $eventDate->copy()->addHour(),
                'status' => $status,
                'all_day' => false,
                'created_at' => $eventDate->copy()->subDays(rand(1, 7)),
                'updated_at' => now(),
            ]);
            $count++;
        }
        $this->command->info("  D. Demo calendar events seeded: {$count}");
    }

    // ─── E. DEMO BUYER ACTIVITY ─────────────────────────────

    private function seedDemoBuyerActivity(): void
    {
        $demoContactIds = DB::table('contacts')
            ->where('agency_id', $this->agencyId)
            ->where('first_name', 'like', '[DEMO]%')
            ->where('is_buyer', 1)
            ->pluck('id')->toArray();

        $propertyIds = DB::table('properties')
            ->where('agency_id', $this->agencyId)
            ->where('status', 'available')
            ->pluck('id')->toArray();

        if (empty($demoContactIds) || empty($propertyIds)) return;

        $count = 0;
        foreach (array_slice($demoContactIds, 0, 20) as $contactId) {
            for ($r = 0; $r < rand(1, 3); $r++) {
                $propId = $propertyIds[array_rand($propertyIds)];
                DB::table('buyer_property_responses')->insertOrIgnore([
                    'contact_id' => $contactId,
                    'agency_id' => $this->agencyId,
                    'property_id' => $propId,
                    'response' => ['interested', 'interested', 'viewing_requested', 'not_interested'][rand(0, 3)],
                    'source' => 'buyer_portal',
                    'responded_at' => now()->subDays(rand(0, 14)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }

            DB::table('buyer_activity_log')->insert([
                'contact_id' => $contactId,
                'agency_id' => $this->agencyId,
                'activity_type' => ['viewing_completed', 'call_logged', 'email_sent', 'note_added', 'manual'][rand(0, 4)],
                'activity_date' => now()->subDays(rand(0, 30)),
                'logged_by_user_id' => $this->agentIds[array_rand($this->agentIds)],
                'created_at' => now(),
            ]);
        }
        $this->command->info("  E. Demo buyer activity seeded: {$count} responses");
    }
}
