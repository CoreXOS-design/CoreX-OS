<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\User;
use App\Services\Prospecting\PropertyIdentityApplier;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §16: the apply steps that follow the A4 / A5 reports — adverts attach to the file that
 * has an address, files that disagree stay separate and are flagged, an over-merged file splits by unit, the share step
 * leaves the test agency out and never writes a private column, and the possible-matches screen is agency-scoped.
 */
final class PropertyIdentityApplyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $admin;
    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->other = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $brB = Branch::create(['agency_id' => $this->other->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $br->id, 'role' => 'admin']);
        $this->otherAdmin = User::factory()->create(['agency_id' => $this->other->id, 'branch_id' => $brB->id, 'role' => 'admin']);
    }

    private function file(array $attrs = [], ?int $agencyId = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => $agencyId ?? $this->agency->id, 'status' => 'active', 'first_seen_at' => now()], $attrs));
    }

    private function ref(TrackedProperty $tp, string $type, string $ref): TrackedPropertyExternalRef
    {
        return TrackedPropertyExternalRef::create(['agency_id' => $tp->agency_id, 'tracked_property_id' => $tp->id, 'source_type' => $type, 'source_ref' => $ref, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    private function applier(): PropertyIdentityApplier
    {
        return app(PropertyIdentityApplier::class);
    }

    private function micRow(TrackedProperty $tp, string $ref, string $address, string $suburb = 'Uvongo', ?int $agencyId = null): ProspectingListing
    {
        return ProspectingListing::create([
            'agency_id' => $agencyId ?? $tp->agency_id, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp->id,
            'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://www.property24.com/x/' . $ref, 'address' => $address, 'suburb' => $suburb,
            'price' => 1_500_000, 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ]);
    }

    // ---- advert clashes -----------------------------------------------------------------------------------------------

    public function test_an_advert_on_a_file_with_no_address_moves_to_the_file_that_has_one(): void
    {
        $addressed = $this->file(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $shell = $this->file([]);
        $this->ref($addressed, 'p24', 'P24-100000001');
        $this->ref($shell, 'property24', '100000001');

        $dry = $this->applier()->advertClashes($this->agency->id, false);
        $this->assertSame(1, $dry['groups']);
        $this->assertSame(0, $dry['shells_flagged_duplicate'], 'a dry run flags nothing');
        $this->assertSame($shell->id, (int) DB::table('tracked_property_external_refs')->where('source_type', 'property24')->value('tracked_property_id'), 'a dry run moves nothing');

        $res = $this->applier()->advertClashes($this->agency->id, true);

        $this->assertSame(1, $res['adverts_attached']);
        $this->assertSame($addressed->id, (int) DB::table('tracked_property_external_refs')->where('source_type', 'property24')->value('tracked_property_id'));
        $shell->refresh();
        $this->assertSame('duplicate', $shell->status, 'the emptied shell is flagged, not deleted');
        $this->assertSame($addressed->id, (int) $shell->duplicate_of_tracked_property_id);
        $this->assertNull($shell->deleted_at);
    }

    public function test_two_files_with_different_house_numbers_are_never_joined_on_the_advert_alone(): void
    {
        $a = $this->file(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $b = $this->file(['street_number' => '22', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $this->ref($a, 'p24', 'P24-100000002');
        $this->ref($b, 'property24', '100000002');

        $res = $this->applier()->advertClashes($this->agency->id, true);

        $this->assertSame(0, $res['adverts_attached']);
        $this->assertSame(0, $res['merged_files']);
        $this->assertSame(1, $res['flagged_pairs']);
        $this->assertSame('active', $b->refresh()->status);
        $this->assertSame($b->id, (int) DB::table('tracked_property_external_refs')->where('source_type', 'property24')->value('tracked_property_id'));
        $this->assertSame(1, DB::table('property_match_decisions')->where('subject_type', 'identity_possible')->whereNull('confirmed_at')->whereNull('rejected_at')->count(), 'flagged for the review list');

        // run again: nothing new is recorded
        $again = $this->applier()->advertClashes($this->agency->id, true);
        $this->assertSame(0, $again['flagged_pairs']);
        $this->assertSame(1, DB::table('property_match_decisions')->where('subject_type', 'identity_possible')->count());
    }

    public function test_an_empty_capture_shell_attaches_to_a_suburb_only_file_but_two_equal_files_are_left(): void
    {
        $suburbOnly = $this->file(['suburb' => 'Ramsgate']);
        $shell = $this->file([]);
        $this->ref($suburbOnly, 'p24', 'P24-100000004');
        $this->ref($shell, 'property24', '100000004');
        $twinA = $this->file(['suburb' => 'Ramsgate']);
        $twinB = $this->file(['suburb' => 'Ramsgate']);
        $this->ref($twinA, 'p24', 'P24-100000005');
        $this->ref($twinB, 'property24', '100000005');

        $res = $this->applier()->advertClashes($this->agency->id, true);

        $this->assertSame(1, $res['adverts_attached']);
        $this->assertSame($suburbOnly->id, (int) DB::table('tracked_property_external_refs')->where('source_ref', '100000004')->value('tracked_property_id'));
        $this->assertSame('duplicate', $shell->refresh()->status);
        $this->assertSame($twinB->id, (int) DB::table('tracked_property_external_refs')->where('source_ref', '100000005')->value('tracked_property_id'), 'equally (un)addressed files are never joined on the advert alone');
        $this->assertSame(1, $res['left_no_address_anywhere']);
    }

    public function test_two_files_without_any_address_are_left_alone(): void
    {
        $a = $this->file([]);
        $b = $this->file([]);
        $this->ref($a, 'p24', 'P24-100000003');
        $this->ref($b, 'property24', '100000003');

        $res = $this->applier()->advertClashes($this->agency->id, true);

        $this->assertSame(1, $res['left_no_address_anywhere']);
        $this->assertSame('active', $b->refresh()->status);
    }

    // ---- over-merged files --------------------------------------------------------------------------------------------

    public function test_an_over_merged_file_splits_into_one_file_per_address_and_the_adverts_follow(): void
    {
        $file = $this->file(['street_number' => '10', 'street_name' => 'Lagoon Drive', 'street_core' => 'lagoon', 'suburb' => 'Umhlanga']);
        $n = 100000100;
        foreach (['10 Lagoon Drive', '10 Lagoon Drive', '10 Lagoon Drive', '12 Lagoon Drive', '14 Lagoon Drive', '16 Lagoon Drive'] as $address) {
            $n++;
            $this->micRow($file, 'P24-' . $n, $address, 'Umhlanga');
            $this->ref($file, 'p24', 'P24-' . $n);
        }

        $dry = $this->applier()->splitOverMerged($this->agency->id, false);
        $this->assertSame(1, $dry['files_split']);
        $this->assertSame(3, $dry['new_files']);
        $this->assertSame(1, TrackedProperty::queryWithoutAgencyScope()->count(), 'a dry run creates nothing');

        $res = $this->applier()->splitOverMerged($this->agency->id, true);

        $this->assertSame(3, $res['new_files']);
        $this->assertSame(4, TrackedProperty::queryWithoutAgencyScope()->count());
        $this->assertSame(3, ProspectingListing::where('tracked_property_id', $file->id)->count(), 'the file keeps the adverts at its own address');
        $this->assertSame(3, ProspectingListing::where('tracked_property_id', '<>', $file->id)->distinct()->count('tracked_property_id') ?: 0);
        $this->assertSame(3, DB::table('tracked_property_external_refs')->where('tracked_property_id', $file->id)->count());
        foreach (TrackedProperty::queryWithoutAgencyScope()->where('id', '<>', $file->id)->get() as $new) {
            $this->assertNull($new->owner_contact_id);
            $this->assertNull($new->erf_number, 'a split file carries only advert facts, never the old file\'s deeds data');
            $this->assertSame('Umhlanga', $new->suburb);
        }
    }

    // ---- merger -------------------------------------------------------------------------------------------------------

    public function test_a_merge_folds_the_loser_into_the_winner_and_flags_it(): void
    {
        $win = $this->file(['street_number' => '5', 'street_name' => 'Marine Drive', 'suburb' => 'Margate']);
        $lose = $this->file(['street_number' => '5', 'street_name' => 'Marine Drive', 'suburb' => 'Margate']);
        $this->ref($lose, 'p24', 'P24-100000200');

        $res = app(TrackedPropertyMerger::class)->merge($win, $lose, true);

        $this->assertTrue($res['merged']);
        $this->assertSame($win->id, (int) DB::table('tracked_property_external_refs')->where('source_ref', 'P24-100000200')->value('tracked_property_id'));
        $this->assertSame('duplicate', $lose->refresh()->status);
        $this->assertSame($win->id, (int) $lose->duplicate_of_tracked_property_id);
    }

    public function test_a_merge_is_refused_for_different_erf_numbers_and_different_agencies(): void
    {
        $a = $this->file(['erf_number' => '100']);
        $b = $this->file(['erf_number' => '200']);
        $c = $this->file([], $this->other->id);
        $merger = app(TrackedPropertyMerger::class);

        $this->assertFalse($merger->merge($a, $b, true)['merged']);
        $this->assertFalse($merger->merge($a, $c, true)['merged']);
        $this->assertSame('active', $b->refresh()->status);
    }

    // ---- share --------------------------------------------------------------------------------------------------------

    public function test_share_fills_the_pool_points_the_rows_back_and_skips_the_excluded_agency(): void
    {
        $real = $this->file(['street_number' => '3', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'erf_number' => '4411', 'title_deed_number' => 'T123']);
        $test = $this->file(['street_number' => '3', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], $this->other->id);
        $this->micRow($real, 'P24-100000300', '3 Beach Road', 'Uvongo');
        $this->ref($real, 'p24', 'P24-100000300');
        $this->micRow($test, 'P24-100000300', '3 Beach Road', 'Uvongo', $this->other->id);
        $this->ref($test, 'p24', 'P24-100000300');

        $dry = $this->applier()->share([$this->other->id], false);
        $this->assertSame(1, $dry['adverts_in_scope'], 'the excluded agency\'s adverts are out of scope');
        $this->assertSame(0, DB::table('portal_advert_facts')->count(), 'a dry run writes nothing');

        $res = $this->applier()->share([$this->other->id], true);

        $this->assertSame(1, $res['shared_properties_created']);
        $this->assertSame(1, DB::table('portal_advert_facts')->count());
        $shared = DB::table('shared_properties')->first();
        $this->assertSame((int) $shared->id, (int) DB::table('portal_advert_facts')->value('shared_property_id'));
        $this->assertSame((int) $shared->id, (int) $real->refresh()->shared_property_id);
        $this->assertNull($test->refresh()->shared_property_id, 'the test agency is never pointed at the pool');
        $this->assertSame([$this->agency->id], DB::table('portal_advert_contributions')->pluck('agency_id')->map(fn ($i) => (int) $i)->all(), 'who contributed is kept, internally');
        $this->assertNotNull(ProspectingListing::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('portal_advert_fact_id'));
        $this->assertNull(ProspectingListing::withoutGlobalScopes()->where('agency_id', $this->other->id)->value('portal_advert_fact_id'));

        $columns = array_keys((array) $shared);
        foreach (['agency_id', 'erf_number', 'title_deed_number', 'owner_contact_id', 'tracked_property_id'] as $private) {
            $this->assertNotContains($private, $columns, "{$private} must never be on the shared side");
        }
        $this->assertFalse($res['privacy']['shared_tables_have_agency_column']);

        // idempotent
        $again = $this->applier()->share([$this->other->id], true);
        $this->assertSame(0, $again['shared_properties_created']);
        $this->assertSame(1, DB::table('shared_properties')->count());
    }

    // ---- Opportunities "with address" ----------------------------------------------------------------------------------

    public function test_with_address_counts_any_file_with_a_street_not_only_the_primary_address_row(): void
    {
        $onRow = $this->file(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $onHistoryRow = $this->file([]);
        DB::table('tracked_property_addresses')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $onHistoryRow->id, 'street_name' => 'Beta Road', 'source_type' => 'manual', 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $none = $this->file([]);

        $with = TrackedProperty::queryWithoutAgencyScope()->hasStreet()->pluck('id')->all();
        $without = TrackedProperty::queryWithoutAgencyScope()->lacksStreet()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$onRow->id, $onHistoryRow->id], $with);
        $this->assertSame([$none->id], $without);
    }

    // ---- possible matches screen -------------------------------------------------------------------------------------

    private function pending(TrackedProperty $left, TrackedProperty $right, string $advert = 'p24:100000400'): int
    {
        return DB::table('property_match_decisions')->insertGetId([
            'agency_id' => $left->agency_id, 'subject_type' => 'identity_possible', 'subject_key' => $advert, 'matched_type' => 'tracked_property', 'matched_id' => $right->id,
            'strategy' => 'portal_ref', 'reason' => 'Probable match: the same advert', 'incoming_facts' => json_encode(['kind' => 'advert_clash', 'incoming_tracked_property_id' => $left->id]),
            'decided_at' => now(), 'outcome' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_screen_lists_only_the_viewers_agency_and_blocks_a_direct_id(): void
    {
        $a1 = $this->file(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $a2 = $this->file(['street_number' => '2', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $b1 = $this->file(['street_number' => '9', 'street_name' => 'Zulu Street', 'suburb' => 'Margate'], $this->other->id);
        $b2 = $this->file(['street_number' => '8', 'street_name' => 'Zulu Street', 'suburb' => 'Margate'], $this->other->id);
        $mine = $this->pending($a1, $a2);
        $theirs = $this->pending($b1, $b2, 'p24:100000401');

        $this->actingAs($this->admin)->get(route('corex.possible-matches.index'))->assertOk()->assertSee('Alpha Road')->assertDontSee('Zulu Street');
        $this->actingAs($this->admin)->get(route('corex.possible-matches.index', ['q' => 'Zulu']))->assertOk()->assertDontSee('Zulu Street');
        $this->actingAs($this->admin)->get(route('corex.possible-matches.index', ['q' => 'alpha']))->assertOk()->assertSee('Alpha Road');
        $this->actingAs($this->admin)->post(route('corex.possible-matches.confirm', $theirs))->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.possible-matches.reject', $theirs))->assertNotFound();
        $this->assertNull(DB::table('property_match_decisions')->where('id', $theirs)->value('rejected_at'));
        $this->assertNotNull($mine);
    }

    public function test_not_the_same_is_remembered_and_can_be_undone(): void
    {
        $a1 = $this->file(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $a2 = $this->file(['street_number' => '2', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $id = $this->pending($a1, $a2);

        $this->actingAs($this->admin)->post(route('corex.possible-matches.reject', $id))->assertRedirect();
        $this->assertNotNull(DB::table('property_match_decisions')->where('id', $id)->value('rejected_at'));
        $this->actingAs($this->admin)->get(route('corex.possible-matches.index'))->assertDontSee('Alpha Road');
        $this->actingAs($this->admin)->get(route('corex.possible-matches.index', ['status' => 'rejected']))->assertSee('Alpha Road');

        $this->actingAs($this->admin)->post(route('corex.possible-matches.restore', $id))->assertRedirect();
        $this->assertNull(DB::table('property_match_decisions')->where('id', $id)->value('rejected_at'));
    }

    public function test_same_property_merges_two_files_and_links_an_own_listing(): void
    {
        $a1 = $this->file(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $a2 = $this->file(['street_number' => '1', 'street_name' => 'Alpha Rd', 'suburb' => 'Uvongo']);
        $this->ref($a2, 'p24', 'P24-100000500');
        $id = $this->pending($a1, $a2);

        $this->actingAs($this->admin)->post(route('corex.possible-matches.confirm', $id))->assertRedirect();

        $this->assertNotNull(DB::table('property_match_decisions')->where('id', $id)->value('confirmed_at'));
        $statuses = [$a1->refresh()->status, $a2->refresh()->status];
        $this->assertContains('duplicate', $statuses);
        $this->assertSame(1, DB::table('tracked_properties')->where('agency_id', $this->agency->id)->where('status', 'duplicate')->count());

        // an own listing: the link is saved, nothing is promoted
        $c = $this->file(['street_number' => '7', 'street_name' => 'Gamma Road', 'suburb' => 'Uvongo']);
        $propertyId = DB::table('properties')->insertGetId(['agency_id' => $this->agency->id, 'address' => '7 Gamma Road, Uvongo', 'suburb' => 'Uvongo', 'status' => 'active', 'title' => '7 Gamma Road', 'agent_id' => $this->admin->id, 'branch_id' => $this->admin->branch_id, 'external_id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $d = DB::table('property_match_decisions')->insertGetId([
            'agency_id' => $this->agency->id, 'subject_type' => 'identity_possible', 'subject_key' => 'property:' . $propertyId, 'matched_type' => 'tracked_property', 'matched_id' => $c->id,
            'strategy' => 'address_unit', 'reason' => 'Probable match: same street', 'incoming_facts' => json_encode(['kind' => 'own_stock', 'property_id' => $propertyId]),
            'decided_at' => now(), 'outcome' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin)->post(route('corex.possible-matches.confirm', $d))->assertRedirect();
        $this->assertSame($c->id, (int) DB::table('properties')->where('id', $propertyId)->value('tracked_property_id'));
        $this->assertNull($c->refresh()->promoted_to_property_id, 'a confirmed link is not a claim');
    }
}
