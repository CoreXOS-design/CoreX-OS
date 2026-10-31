<?php

namespace Tests\Feature\TrainingVideos;

use App\Models\Agency;
use App\Services\TrainingVideos\Auto\TrainingAgencySeeder;
use App\Services\TrainingVideos\Auto\TrainingMicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-525 (spec section 9.11) - the invented Market Intelligence data of the training agency: only ever in that agency, never real, idempotent, resettable.
 */
final class TrainingMicSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_kind_of_row_the_work_tab_needs_into_the_training_agency_only(): void
    {
        $other = Agency::create(['name' => 'Real Agency', 'slug' => 'real-' . uniqid()]);
        $r = app(TrainingAgencySeeder::class)->seed();
        $aid = $r['agency']->id;

        $this->assertGreaterThanOrEqual(25, DB::table('tracked_properties')->where('agency_id', $aid)->count());
        $this->assertSame(0, DB::table('tracked_properties')->where('agency_id', $other->id)->count());
        foreach (['prospecting_listings', 'p24_listings', 'tracked_property_agency_work', 'tracked_property_external_refs'] as $t) {
            $this->assertGreaterThan(0, DB::table($t)->where('agency_id', $aid)->count(), $t);
            $this->assertSame(0, DB::table($t)->where('agency_id', '!=', $aid)->count(), $t . ' must hold nothing for any other agency');
        }
        $statuses = DB::table('prospecting_listings')->where('agency_id', $aid)->pluck('portal_status')->unique()->all();
        foreach (['active', 'under_offer', 'sold'] as $s) {
            $this->assertContains($s, $statuses);
        }
        $this->assertGreaterThan(0, DB::table('prospecting_price_history')->count());
        $this->assertTrue(DB::table('prospecting_listings')->where('agency_id', $aid)->where('advertiser_type', 'owner')->exists(), 'an owner-selling advert');
    }

    public function test_nothing_in_it_is_a_real_portal_advert_or_a_real_address(): void
    {
        app(TrainingAgencySeeder::class)->seed();
        $urls = DB::table('prospecting_listings')->pluck('portal_url')->merge(DB::table('p24_listings')->pluck('p24_url'));
        foreach ($urls as $u) {
            $this->assertStringContainsString('.invalid/', (string) $u, 'every advert link is on the .invalid domain');
        }
        foreach (DB::table('tracked_properties')->pluck('street_name')->unique() as $street) {
            $this->assertContains($street, ['Academy Lane', 'Tutor Crescent', 'Lesson Close', 'Example Drive']);
        }
        foreach (DB::table('contacts')->where('email', 'like', '%.example@%')->get(['last_name', 'email']) as $c) {
            $this->assertSame('Example', $c->last_name);
            $this->assertStringEndsWith('.invalid', $c->email);
        }
    }

    public function test_the_three_trigger_kinds_and_their_neighbours_exist_for_the_neighbours_panel(): void
    {
        $r = app(TrainingAgencySeeder::class)->seed();
        $user = $r['users']['agent'];
        $panel = app(\App\Services\Prospecting\MicWork\NeighboursPanelProvider::class);
        $rows = $panel->rows($r['agency']->id, $user, ['months' => 1]);

        $this->assertEqualsCanonicalizing(['registered', 'sold', 'under_offer'], array_values(array_unique(array_column($rows, 'type'))));
        foreach ($rows as $row) {
            $around = $panel->around($r['agency']->id, $user, $row['id']);
            $this->assertGreaterThanOrEqual(4, count($around), $row['id'] . ' has neighbours');
        }
    }

    public function test_it_is_idempotent_and_reset_starts_clean(): void
    {
        $seeder = app(TrainingAgencySeeder::class);
        $r = $seeder->seed();
        $count = DB::table('tracked_properties')->where('agency_id', $r['agency']->id)->count();
        $seeder->seed();
        $this->assertSame($count, DB::table('tracked_properties')->where('agency_id', $r['agency']->id)->count());

        DB::table('prospecting_listings')->where('agency_id', $r['agency']->id)->limit(1)->delete();
        $seeder->reset();
        $this->assertSame($count, DB::table('tracked_properties')->where('agency_id', $r['agency']->id)->count());
        $this->assertSame(0, DB::table('tracked_properties')->where('agency_id', '!=', $r['agency']->id)->count());
    }

    public function test_it_refuses_an_agency_that_is_not_a_training_agency(): void
    {
        $real = Agency::create(['name' => 'Real Agency', 'slug' => 'real-' . uniqid()]);
        $user = \App\Models\User::factory()->create(['agency_id' => $real->id]);
        $this->expectException(\RuntimeException::class);
        app(TrainingMicSeeder::class)->seed($real, $user);
    }
}
