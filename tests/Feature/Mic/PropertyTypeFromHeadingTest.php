<?php

declare(strict_types=1);

namespace Tests\Feature\Mic;

use App\Models\Agency;
use App\Models\ProspectingListing;
use App\Services\Prospecting\MicAdvertFacts;
use App\Services\Prospecting\MicWork\MicWorkList;
use App\Services\Prospecting\PropertyTypeFromHeading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.19 - the type a portal heading names, by whole word ("Townhouse" is not "house"), the ingest that cannot be re-broken by an old
 * extension, the journalled fix command, and a file's type following its live lead advert.
 */
final class PropertyTypeFromHeadingTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
    }

    public function test_the_heading_is_read_by_whole_word_leftmost_longest_first(): void
    {
        $this->assertSame('Townhouse', PropertyTypeFromHeading::derive('2 Bedroom Townhouse'));
        $this->assertSame('House', PropertyTypeFromHeading::derive('3 Bedroom House'));
        $this->assertSame('Apartment', PropertyTypeFromHeading::derive('2 Bedroom Apartment / Flat'));
        $this->assertSame('Flat', PropertyTypeFromHeading::derive('1 Bedroom Flat'));
        $this->assertSame('Duplex', PropertyTypeFromHeading::derive('3 Bedroom Duplex'));
        $this->assertSame('Simplex', PropertyTypeFromHeading::derive('2 Bedroom Simplex'));
        $this->assertSame('Vacant land', PropertyTypeFromHeading::derive('Vacant Land for sale'));
        $this->assertSame('House', PropertyTypeFromHeading::derive('4 Bedroom House with granny flat'), 'leftmost wins');
        $this->assertNull(PropertyTypeFromHeading::derive('Lovely home near the beach'));
        $this->assertNull(PropertyTypeFromHeading::derive(''));
    }

    public function test_reconcile_overrides_only_a_contradiction(): void
    {
        $a = $this->agency->id;
        $this->assertSame('Townhouse', PropertyTypeFromHeading::reconcile('House', '2 Bedroom Townhouse', $a));
        $this->assertSame('Apartment', PropertyTypeFromHeading::reconcile('Apartment', '1 Bedroom Flat', $a), 'the same canonical type is left alone');
        $this->assertSame('House', PropertyTypeFromHeading::reconcile('House', '3 Bedroom House', $a));
        $this->assertSame('Townhouse', PropertyTypeFromHeading::reconcile('', '2 Bedroom Townhouse', $a));
        $this->assertSame('House', PropertyTypeFromHeading::reconcile('House', '2 Bedroom Cluster', $a), 'a word with no canonical type never overrides');
        $this->assertSame('House', PropertyTypeFromHeading::reconcile('House', null, $a));
    }

    private function advert(array $extra = []): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . random_int(100000000, 999999999), 'portal_url' => 'https://p.test/x', 'address' => '1 Test Road',
            'suburb' => 'Uvongo', 'property_type' => 'House', 'price' => 900_000, 'bedrooms' => 2, 'captured_by_user_id' => $this->agent->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
    }

    public function test_the_ingest_apply_corrects_a_house_sent_for_a_townhouse(): void
    {
        $l = $this->advert(['property_type' => 'House']);
        MicAdvertFacts::apply($l, ['columns' => ['card_heading' => '2 Bedroom Townhouse'], 'listed_date' => null, 'page_read' => false], now());
        $this->assertSame('Townhouse', $l->property_type);

        $ok = $this->advert(['property_type' => 'Apartment']);
        MicAdvertFacts::apply($ok, ['columns' => ['card_heading' => '2 Bedroom Apartment'], 'listed_date' => null, 'page_read' => false], now());
        $this->assertSame('Apartment', $ok->property_type);
    }

    public function test_the_command_is_a_dry_run_by_default_then_applies_and_journals(): void
    {
        $bad = $this->advert(['property_type' => 'House']);
        $good = $this->advert(['property_type' => 'House']);
        DB::table('prospecting_listings')->where('id', $bad->id)->update(['card_heading' => '2 Bedroom Townhouse']);
        DB::table('prospecting_listings')->where('id', $good->id)->update(['card_heading' => '3 Bedroom House']);
        $dir = sys_get_temp_dir() . '/mic-type-journal-' . uniqid();

        Artisan::call('mic:fix-property-types', ['--agency' => $this->agency->id, '--journal-dir' => $dir]);
        $this->assertSame('House', DB::table('prospecting_listings')->where('id', $bad->id)->value('property_type'), 'dry run changes nothing');
        $this->assertStringContainsString('DRY RUN - adverts changed: 1', Artisan::output());
        $this->assertDirectoryDoesNotExist($dir);

        Artisan::call('mic:fix-property-types', ['--agency' => $this->agency->id, '--apply' => true, '--journal-dir' => $dir]);
        $this->assertSame('Townhouse', DB::table('prospecting_listings')->where('id', $bad->id)->value('property_type'));
        $this->assertSame('House', DB::table('prospecting_listings')->where('id', $good->id)->value('property_type'));
        $journal = glob($dir . '/property-types-agency' . $this->agency->id . '-*.jsonl');
        $this->assertCount(1, $journal);
        $line = json_decode(trim(file_get_contents($journal[0])), true);
        $this->assertSame(['prospecting_listings', $bad->id, 'House', 'Townhouse'], [$line['table'], $line['id'], $line['old'], $line['new']]);
        array_map('unlink', glob($dir . '/*'));
        @rmdir($dir);
    }

    public function test_a_files_type_follows_its_live_lead_advert_not_the_first_capture(): void
    {
        $file = (int) DB::table('tracked_properties')->insertGetId(['agency_id' => $this->agency->id, 'external_id' => 'ext-' . uniqid(), 'street_number' => '4', 'street_name' => 'Emtunzi Drive', 'suburb' => 'Uvongo', 'property_type' => 'Apartment', 'created_at' => now(), 'updated_at' => now()]);
        $old = $this->advert(['tracked_property_id' => $file, 'property_type' => 'Apartment', 'is_active' => false, 'last_seen_at' => now()->subMonths(3)]);
        $live = $this->advert(['tracked_property_id' => $file, 'property_type' => 'Townhouse', 'is_active' => true, 'last_seen_at' => now()]);

        // the card: the live lead advert's type, not the file's stale "Apartment"
        $rec = app(MicWorkList::class)->forFiles([$file], $this->agency->id)[0];
        $this->assertSame('Townhouse', $rec['facts']['type']);

        // the command brings the file in line
        $dir = sys_get_temp_dir() . '/mic-type-journal-' . uniqid();
        Artisan::call('mic:fix-property-types', ['--agency' => $this->agency->id, '--apply' => true, '--journal-dir' => $dir]);
        $this->assertSame('Townhouse', DB::table('tracked_properties')->where('id', $file)->value('property_type'));
        array_map('unlink', glob($dir . '/*'));
        @rmdir($dir);
    }
}
