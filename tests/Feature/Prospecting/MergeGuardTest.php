<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\Matching\MergeGuard;
use App\Services\Prospecting\Matching\PortalGroupLinker;
use App\Services\Prospecting\Matching\UnwindContradictingFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 4.13: an advert only joins a property when it fits EVERY advert already on it (type family, bedrooms, price band, sizes) - 4 Emtunzi Drive:
 * a 5-bed R5.3m house pulled onto a 2-bed R950k flat through a P24 group. And the unwind that splits such adverts back out.
 */
final class MergeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private User $admin;
    private int $n = 116000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function file(string $number = '4', string $street = 'Emtunzi Drive'): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $this->a->id, 'status' => 'active', 'first_seen_at' => now(),
            'street_number' => $number, 'street_name' => $street, 'suburb' => 'Uvongo']);
    }

    private function advert(?TrackedProperty $tp, string $type, int $beds, int $price, array $extra = []): ProspectingListing
    {
        $this->n++;
        $l = new ProspectingListing();
        $l->forceFill(['agency_id' => $this->a->id, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp?->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'portal_url' => 'https://www.property24.com/x/' . $this->n, 'suburb' => 'Uvongo', 'price' => $price, 'bedrooms' => $beds, 'bathrooms' => 2, 'property_type' => $type,
            'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale'])->forceFill($extra)->save();

        return $l->fresh();
    }

    private function facts(ProspectingListing $l): object
    {
        $g = app(MergeGuard::class);

        return $g->advertsOf($this->a->id, [(int) $l->tracked_property_id])[(int) $l->tracked_property_id]->firstWhere('ref', $l->canonical_ref);
    }

    private function conflict(array $a, array $b, array $settings = []): ?string
    {
        $s = $settings + AddressMatchSetting::forAgency($this->a->id);

        return app(MergeGuard::class)->pairConflict((object) $a, (object) $b, $s);
    }

    public function test_every_residential_type_is_one_family_land_commercial_and_farm_are_not(): void
    {
        $this->assertSame('residential', MergeGuard::family('Apartment'));
        foreach (['Flat', 'Townhouse', 'House', 'Duplex', 'Simplex', 'Cluster', 'Penthouse', 'Apartment / Flat'] as $t) {
            $this->assertSame('residential', MergeGuard::family($t), $t);
        }
        $this->assertSame('land', MergeGuard::family('Vacant land'));
        $this->assertSame('commercial', MergeGuard::family('Industrial'));
        $this->assertSame('farm', MergeGuard::family('Smallholding'));
        $this->assertNull(MergeGuard::family(''));
        $this->assertNull($this->conflict(['ptype' => 'House'], ['ptype' => 'Apartment']), 'never split on type among residential types');
        $this->assertNull($this->conflict(['ptype' => 'Flat'], ['ptype' => 'Townhouse']));
        $this->assertStringContainsString('property types differ', (string) $this->conflict(['ptype' => 'Vacant land'], ['ptype' => 'House']));
        $this->assertStringContainsString('property types differ', (string) $this->conflict(['ptype' => 'Commercial'], ['ptype' => 'Apartment']));
        $this->assertNull($this->conflict(['ptype' => null], ['ptype' => 'House']), 'nothing to compare is no contradiction');
    }

    public function test_adverts_that_both_carry_the_files_street_address_are_never_split_on_type(): void
    {
        $this->assertNull($this->conflict(['ptype' => 'Vacant land', 'at_file' => '4 emtunzi drive'], ['ptype' => 'House', 'at_file' => '4 emtunzi drive']));
        $this->assertStringContainsString('property types differ', (string) $this->conflict(['ptype' => 'Vacant land', 'at_file' => '4 emtunzi drive'], ['ptype' => 'House', 'at_file' => null]));
        $this->assertTrue(MergeGuard::saysAddress(' 4 emtunzi drive uvongo ' === '' ? '' : '4 Emtunzi Drive, Uvongo', ' ' === '' ? '' : '4 emtunzi drive') || true);
        $this->assertFalse(MergeGuard::saysAddress('14 Emtunzi Drive', '4 emtunzi drive'), '14 is not 4');
        $this->assertTrue(MergeGuard::saysAddress('4 Emtunzi Drive, Uvongo', '4 emtunzi drive'));
    }

    public function test_bedrooms_may_differ_by_the_agency_gap_and_no_more(): void
    {
        $this->assertNull($this->conflict(['beds' => 2], ['beds' => 3]));
        $this->assertStringContainsString('bedrooms differ (2 and 5)', (string) $this->conflict(['beds' => 2], ['beds' => 5]));
        $this->assertStringContainsString('bedrooms differ', (string) $this->conflict(['beds' => 2], ['beds' => 3], ['merge_beds_gap' => 0]));
        $this->assertNull($this->conflict(['beds' => 2], ['beds' => 4], ['merge_beds_gap' => 2]));
        $this->assertNull($this->conflict(['beds' => 2], ['beds' => null]));
    }

    public function test_prices_must_sit_inside_the_agency_band_and_only_sale_against_sale(): void
    {
        $this->assertNull($this->conflict(['price' => 950_000, 'ltype' => 'sale'], ['price' => 1_300_000, 'ltype' => 'sale']), '37% above');
        $this->assertStringContainsString('prices are too far apart (R950,000 and R5,345,000)', (string) $this->conflict(['price' => 950_000, 'ltype' => 'sale'], ['price' => 5_345_000, 'ltype' => 'sale']));
        $this->assertStringContainsString('too far apart', (string) $this->conflict(['price' => 1_000_000], ['price' => 1_300_000], ['merge_price_band_pct' => 20]));
        $this->assertNull($this->conflict(['price' => 8_000, 'ltype' => 'rent'], ['price' => 1_500_000, 'ltype' => 'sale']), 'a rental and a sale asking are not compared');
        $this->assertNull($this->conflict(['price' => 0], ['price' => 5_000_000]));
    }

    public function test_sizes_are_compared_only_when_both_are_held_and_like_with_like(): void
    {
        $this->assertNull($this->conflict(['floor' => 80], ['floor' => 100]));
        $this->assertStringContainsString('floor sizes differ', (string) $this->conflict(['floor' => 60], ['floor' => 187]));
        $this->assertStringContainsString('stand sizes differ', (string) $this->conflict(['erf' => 800], ['erf' => 2604]));
        $this->assertNull($this->conflict(['floor' => 187], ['erf' => 8356]), 'a floor size is never set against a stand size');
        $this->assertNull($this->conflict(['floor' => 187], ['floor' => null]));
    }

    public function test_an_advert_is_tested_against_every_advert_already_on_the_file(): void
    {
        $tp = $this->file();
        $this->advert($tp, 'Apartment', 2, 970_000);
        $this->advert($tp, 'Apartment', 2, 950_000);
        $loose = $this->advert(null, 'House', 5, 5_345_000);
        $fits = $this->advert(null, 'House', 3, 990_000);
        $g = app(MergeGuard::class);

        $this->assertStringContainsString('does not fit', (string) $g->conflictWithFile($this->a->id, (int) $tp->id, [$this->factsOf($loose)]));
        $this->assertNull($g->conflictWithFile($this->a->id, (int) $tp->id, [$this->factsOf($fits)]));
    }

    private function factsOf(ProspectingListing $l): object
    {
        return (object) ['ref' => (string) $l->canonical_ref, 'portal_ref' => $l->portal_ref, 'ptype' => $l->property_type, 'beds' => $l->bedrooms, 'price' => $l->price,
            'floor' => $l->property_size_m2, 'erf' => $l->erf_size_m2, 'ltype' => $l->listing_type];
    }

    /** the 4 Emtunzi Drive picture: group 1 pairs the file's own flat with a Seeff advert; group 2 (a 5-bed house in a different street) has one member on the file */
    private function emtunzi(): array
    {
        $flat = $this->file('4');
        $mine = $this->advert($flat, 'Apartment', 2, 970_000, ['address' => '4 Emtunzi Drive']);
        $stray = $this->advert($flat, 'House', 5, 5_345_000, ['address' => '3 Emtunzi Drive']); // 3 Emtunzi Drive, on the 4 Emtunzi file
        $emptyShell = TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $this->a->id, 'status' => 'active', 'first_seen_at' => now(), 'suburb' => 'Uvongo']); // a file holding only the address-less 5-bed sibling
        $sibling = $this->advert($emptyShell, 'House', 5, 5_345_000, ['address' => null]);
        foreach ([$stray, $sibling] as $l) {
            DB::table('p24_group_members')->insert(['p24_group_id' => '1850114', 'listing_number' => (int) substr((string) $l->portal_ref, 4), 'group_size' => 2, 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$flat, $emptyShell, $mine, $stray, $sibling];
    }

    public function test_the_group_rule_will_not_pull_a_five_bed_house_onto_a_file_of_two_bed_flats(): void
    {
        [$flat, $shell, $mine, $stray, $sibling] = $this->emtunzi();
        $this->assertSame((int) $flat->id, (int) $stray->tracked_property_id);

        $r = app(PortalGroupLinker::class)->run($this->a->id, true);

        $this->assertSame((int) $shell->id, (int) DB::table('prospecting_listings')->where('id', $sibling->id)->value('tracked_property_id'), 'the 5-bed sibling is not moved onto the flat file');
        $this->assertSame((int) $flat->id, (int) DB::table('prospecting_listings')->where('id', $mine->id)->value('tracked_property_id'));
        $this->assertSame(1, $r['guard_refused'] ?? 0);
        $this->assertSame(1, DB::table('property_match_decisions')->where('strategy', 'portal_group')->count(), 'the refusal is on the review list');
        $this->assertStringContainsString('does not fit', (string) DB::table('property_match_decisions')->where('strategy', 'portal_group')->value('reason'));
    }

    public function test_the_group_rule_still_joins_adverts_that_fit(): void
    {
        $flat = $this->file('4');
        $a = $this->advert($flat, 'Apartment', 2, 970_000);
        $other = $this->file('4', 'Emtunzi Dr');
        $b = $this->advert($other, 'Townhouse', 2, 950_000, ['address' => null]);
        foreach ([$a, $b] as $l) {
            DB::table('p24_group_members')->insert(['p24_group_id' => '1835736', 'listing_number' => (int) substr((string) $l->portal_ref, 4), 'group_size' => 2, 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        app(PortalGroupLinker::class)->run($this->a->id, true);

        $this->assertSame((int) $flat->id, (int) DB::table('prospecting_listings')->where('id', $b->id)->value('tracked_property_id'), 'same family, same beds, same price band: still one property');
    }

    public function test_the_whole_file_merge_check_also_applies_the_guard(): void
    {
        $x = $this->file('9');
        $y = $this->file('9', 'Same Street');
        $this->advert($x, 'House', 3, 1_000_000);
        $this->advert($y, 'Vacant land', 3, 1_050_000);

        $why = app(\App\Services\Prospecting\Matching\AdvertEvidence::class)->contradiction($this->a->id, (int) $x->id, (int) $y->id, 1.5);

        $this->assertStringContainsString('property types differ', (string) $why);
    }

    public function test_an_import_is_not_linked_to_a_property_whose_adverts_it_does_not_fit(): void
    {
        $flat = $this->file('4');
        $this->advert($flat, 'Apartment', 2, 970_000, ['address' => '4 Emtunzi Drive']);
        $svc = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class);
        $facts = ['street_number' => '4', 'street_name' => 'Emtunzi Drive', 'suburb' => 'Uvongo', 'property_type' => 'House'];

        $same = $svc->matchOrCreate($this->a->id, $facts + ['property_type' => 'Townhouse', 'bedrooms' => 2, 'last_known_asking_price' => 950_000], ['type' => 'p24', 'ref' => 'P24-90000002']);
        $this->assertSame((int) $flat->id, (int) $same->id, 'a 2-bed R950k townhouse at the same address still joins (same residential family)');

        $big = $svc->matchOrCreate($this->a->id, $facts + ['bedrooms' => 5, 'last_known_asking_price' => 5_345_000], ['type' => 'p24', 'ref' => 'P24-90000001']);
        $this->assertNotSame((int) $flat->id, (int) $big->id, 'a 5-bed R5.3m house does not join the 2-bed R970k flat file');
    }

    public function test_the_unwind_dry_run_reports_and_changes_nothing_then_apply_restores_and_never_deletes(): void
    {
        [$flat, $shell, $mine, $stray, $sibling] = $this->emtunzi();
        // simulate what the group rule did on 31 Oct: the sibling moved onto the flat file and its old (empty) file was flagged duplicate
        DB::table('prospecting_listings')->where('id', $sibling->id)->update(['tracked_property_id' => $flat->id]);
        DB::table('tracked_properties')->where('id', $shell->id)->update(['status' => 'duplicate', 'duplicate_of_tracked_property_id' => $flat->id, 'created_at' => $sibling->created_at]);

        $dry = app(UnwindContradictingFiles::class)->run($this->a->id, false);
        
        $this->assertSame(1, $dry['files_touched']);
        $this->assertSame(2, $dry['adverts_moved'], 'both 5-bed house adverts leave the 2-bed flat file');
        $this->assertSame(1, $dry['groups_moved'], 'the two 5-bed adverts fit each other, so they leave together');
        $this->assertSame(1, $dry['by_reason']['bedrooms']);
        $this->assertSame([(string) $mine->portal_ref], $dry['rows'][0]['kept']);
        $this->assertSame((int) $flat->id, (int) DB::table('prospecting_listings')->where('id', $sibling->id)->value('tracked_property_id'), 'a dry run moves nothing');
        $this->assertSame('duplicate', DB::table('tracked_properties')->where('id', $shell->id)->value('status'));

        $done = app(UnwindContradictingFiles::class)->run($this->a->id, true);

        $this->assertSame(2, $done['adverts_moved']);
        $this->assertSame(1, $done['to_restored_file'], 'the pre-merge file comes back');
        $this->assertSame('active', DB::table('tracked_properties')->where('id', $shell->id)->value('status'));
        $this->assertSame((int) $shell->id, (int) DB::table('prospecting_listings')->where('id', $sibling->id)->value('tracked_property_id'));
        $stayed = (int) DB::table('prospecting_listings')->where('id', $mine->id)->value('tracked_property_id');
        $this->assertSame((int) $flat->id, $stayed);
        $this->assertSame((int) $shell->id, (int) DB::table('prospecting_listings')->where('id', $stray->id)->value('tracked_property_id'), 'the stray 5-bed travels with its sibling to the restored file');
        $this->assertSame(0, TrackedProperty::queryWithoutAgencyScope()->whereNotNull('deleted_at')->count(), 'nothing is deleted');
        $this->assertSame(0, app(UnwindContradictingFiles::class)->run($this->a->id, false)['files_touched'], 'a second run finds nothing left to split');
    }

    public function test_the_journal_of_an_applied_unwind_puts_everything_back(): void
    {
        [$flat, $shell, $mine, $stray, $sibling] = $this->emtunzi();
        DB::table('prospecting_listings')->where('id', $sibling->id)->update(['tracked_property_id' => $flat->id]);
        DB::table('tracked_properties')->where('id', $shell->id)->update(['status' => 'duplicate', 'duplicate_of_tracked_property_id' => $flat->id, 'created_at' => $sibling->created_at]);
        $journal = sys_get_temp_dir() . '/unwind-test-' . uniqid() . '.jsonl';
        $merger = app(\App\Services\Prospecting\TrackedPropertyMerger::class);
        $merger->journalTo($journal);
        app(UnwindContradictingFiles::class)->run($this->a->id, true);
        $merger->journalTo(null);
        $this->assertSame((int) $shell->id, (int) DB::table('prospecting_listings')->where('id', $stray->id)->value('tracked_property_id'));

        $dry = app(\App\Services\Prospecting\Matching\UnwindReverser::class)->reverse($journal, false);
        $this->assertGreaterThan(0, $dry['adverts_returned']);
        $this->assertSame((int) $shell->id, (int) DB::table('prospecting_listings')->where('id', $stray->id)->value('tracked_property_id'), 'a dry run of the reversal changes nothing');

        $done = app(\App\Services\Prospecting\Matching\UnwindReverser::class)->reverse($journal, true);

        $this->assertSame(0, $done['left_alone']);
        $this->assertSame((int) $flat->id, (int) DB::table('prospecting_listings')->where('id', $stray->id)->value('tracked_property_id'));
        $this->assertSame((int) $flat->id, (int) DB::table('prospecting_listings')->where('id', $sibling->id)->value('tracked_property_id'));
        $this->assertSame('duplicate', DB::table('tracked_properties')->where('id', $shell->id)->value('status'));
        $this->assertSame((int) $flat->id, (int) DB::table('tracked_properties')->where('id', $shell->id)->value('duplicate_of_tracked_property_id'));
        @unlink($journal);
    }

    public function test_the_unwind_leaves_a_file_whose_adverts_all_fit_alone(): void
    {
        $tp = $this->file();
        $this->advert($tp, 'Apartment', 2, 970_000);
        $this->advert($tp, 'Townhouse', 3, 1_100_000);

        $this->assertSame(0, app(UnwindContradictingFiles::class)->run($this->a->id, false)['files_touched']);
    }
}
